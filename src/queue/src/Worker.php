<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory as QueueManager;
use Hypervel\Contracts\Queue\IndexAwareQueue;
use Hypervel\Contracts\Queue\Interruptible;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\DetectsLostConnections;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobInterrupted;
use Hypervel\Queue\Events\JobPopped;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Queue\Events\Looping;
use Hypervel\Queue\Events\WorkerIdle;
use Hypervel\Queue\Events\WorkerInterrupted;
use Hypervel\Queue\Events\WorkerPausing;
use Hypervel\Queue\Events\WorkerResuming;
use Hypervel\Queue\Events\WorkerStarting;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class Worker
{
    use DetectsLostConnections;

    public const int EXIT_SUCCESS = 0;

    public const int EXIT_ERROR = 1;

    public const int EXIT_MEMORY_LIMIT = 12;

    /**
     * The cache key for the restart signal.
     *
     * IMPORTANT: Uses Laravel's key for cross-framework queue interoperability.
     */
    public const string RESTART_SIGNAL_CACHE_KEY = 'illuminate:queue:restart';

    /**
     * Signals installed when the worker daemon starts.
     */
    protected const array HANDLED_SIGNALS = [
        SIGQUIT,
        SIGTERM,
        SIGINT,
        SIGUSR2,
        SIGCONT,
    ];

    /**
     * The interval between graceful shutdown checks.
     */
    protected const float SHUTDOWN_WAIT_SECONDS = 0.1;

    /**
     * The name of the worker.
     */
    protected ?string $name = null;

    /**
     * The cache repository implementation.
     */
    protected ?CacheContract $cache = null;

    /**
     * The callback used to determine if the application is in maintenance mode.
     *
     * @var callable
     */
    protected $isDownForMaintenance;

    /**
     * The running jobs.
     */
    protected array $runningJobs = [];

    /**
     * The timeout job IDs.
     */
    protected array $timeoutJobIds = [];

    /**
     * The job monitor's ID.
     */
    protected ?int $monitorId = null;

    /**
     * The timer that owns the timeout monitor.
     */
    protected Timer $timer;

    /**
     * Indicates if the job monitor is checking for timeout jobs.
     */
    protected bool $monitorLocked = false;

    /**
     * The number of jobs completed by the worker.
     */
    protected int $jobsProcessed = 0;

    /**
     * The timestamp of the last completed job.
     */
    protected ?float $lastJobProcessedAt = null;

    /**
     * The terminal reason set by an asynchronous worker failure.
     */
    protected ?WorkerStopReason $stopReason = null;

    /**
     * Signals awaiting delivery outside the asynchronous PCNTL handler.
     *
     * @var list<array{signal: int, connectionName: string, queue: string, options: WorkerOptions}>
     */
    protected array $pendingSignals = [];

    /**
     * Indicates if the worker should exit.
     */
    public bool $shouldQuit = false;

    /**
     * Indicates if the worker is paused.
     */
    public bool $paused = false;

    /**
     * The callbacks used to pop jobs from queues.
     *
     * @var callable[]
     */
    protected static $popCallbacks = [];

    /**
     * The custom exit code to be used when memory is exceeded.
     *
     * Boot-only. Mutates process-global worker configuration; runtime use
     * races across coroutines and changes every concurrent worker stop check.
     */
    public static ?int $memoryExceededExitCode = null;

    /**
     * The custom exit code to be used when a job times out.
     *
     * Boot-only. Mutates process-global worker configuration; runtime use
     * races across coroutines and changes every concurrent timeout exit.
     */
    public static ?int $timeoutExceededExitCode = null;

    /**
     * Indicates if the worker should report job exceptions.
     *
     * Boot-only. Mutates process-global worker configuration; runtime use
     * races across coroutines and changes exception reporting for every job.
     */
    public static bool $reportJobExceptions = true;

    /**
     * Indicates if the worker should stop when a lost connection is detected.
     *
     * Boot-only. Mutates process-global worker configuration; runtime use
     * races across coroutines and changes every concurrent worker.
     */
    public static bool $stopOnLostConnection = true;

    /**
     * Indicates if the worker should check for the restart signal in the cache.
     *
     * Boot-only. Mutates process-global worker configuration; runtime use
     * races across coroutines and changes every concurrent restart check.
     */
    public static bool $restartable = true;

    /**
     * Indicates if the worker should check for the paused signal in the cache.
     *
     * Boot-only. Mutates process-global worker configuration; runtime use
     * races across coroutines and changes every concurrent pause check.
     */
    public static bool $pausable = true;

    /**
     * Create a new queue worker.
     *
     * @param QueueManager $manager the queue manager instance
     * @param Dispatcher $events the event dispatcher instance
     * @param ExceptionHandlerContract $exceptions the exception handler instance
     * @param callable $isDownForMaintenance the callback used to determine if the application is in maintenance mode
     */
    public function __construct(
        protected QueueManager $manager,
        protected Dispatcher $events,
        protected ExceptionHandlerContract $exceptions,
        callable $isDownForMaintenance,
        ?Timer $timer = null,
    ) {
        $this->isDownForMaintenance = $isDownForMaintenance;
        $this->timer = $timer ?? new Timer;
    }

    /**
     * Listen to the given queue in a loop.
     */
    public function daemon(string $connectionName, string $queue, WorkerOptions $options): int
    {
        $this->pendingSignals = [];

        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals($connectionName, $queue, $options);
        }

        $startTime = $this->currentTime();
        $jobsAdmitted = 0;

        $this->jobsProcessed = 0;
        $this->lastJobProcessedAt = null;
        $this->stopReason = null;

        $lifecycleWaiter = new Waiter(-1);
        $lastRestart = $lifecycleWaiter->wait(fn (): ?int => $this->withCoroutineContext(
            $options,
            function () use ($connectionName, $queue, $options): ?int {
                $lastRestart = $this->getTimestampOfLastQueueRestart();
                $this->raiseWorkerStartingEvent($connectionName, $queue, $options);

                return $lastRestart;
            },
        ));

        $popWaiter = $this->createPopWaiter();
        $concurrent = new WaitConcurrent($options->concurrency);

        $this->monitorTimeoutJobs($options, $connectionName, $queue);

        try {
            while (true) {
                $this->drainPendingSignals($lifecycleWaiter);

                // Before reserving any jobs, we will make sure this queue is not paused and
                // if it is we will just pause this worker for a given amount of time and
                // make sure we do not need to kill this worker process off completely.
                $shouldRun = $lifecycleWaiter->wait(fn (): bool => $this->withCoroutineContext(
                    $options,
                    fn (): bool => $this->daemonShouldRun($options, $connectionName, $queue),
                ));

                if (! $shouldRun) {
                    /** @var null|array{0: int, 1: WorkerStopReason} $stop */
                    $stop = $lifecycleWaiter->wait(fn (): ?array => $this->withCoroutineContext(
                        $options,
                        fn (): ?array => $this->pauseWorker(
                            $options,
                            $lastRestart,
                            $startTime,
                            $jobsAdmitted,
                        ),
                    ));

                    if ($stop !== null) {
                        [$status, $reason] = $stop;
                        $this->waitForRunningJobs($concurrent);

                        return $this->stop($status, $options, $reason, $connectionName, $queue);
                    }

                    continue;
                }

                // If there are timeout jobs or the concurrency limit is hit, we should
                // not accept new jobs. A full worker waits on capacity so completed jobs
                // wake it immediately instead of limiting throughput to the poll interval.
                $hasTimeoutJobs = $this->hasTimeoutJobs();
                if ($hasTimeoutJobs || $concurrent->isFull()) {
                    $waitInterval = $options->sleep > 0 ? $options->sleep : 1;

                    if ($hasTimeoutJobs) {
                        $this->sleep($waitInterval);
                    } else {
                        $concurrent->waitForAvailableSlot($waitInterval);
                    }

                    /** @var null|array{0: int, 1: WorkerStopReason} $stop */
                    $stop = $lifecycleWaiter->wait(fn (): ?array => $this->withCoroutineContext(
                        $options,
                        fn (): ?array => $this->stopIfNecessary(
                            $options,
                            $lastRestart,
                            $startTime,
                            $jobsAdmitted,
                            checkQueueEmpty: false,
                        ),
                    ));

                    if ($stop !== null) {
                        [$status, $reason] = $stop;

                        $this->waitForRunningJobs($concurrent);

                        return $this->stop($status, $options, $reason, $connectionName, $queue);
                    }

                    continue;
                }

                // First, we will attempt to get the next job off of the queue. Then, we
                // can fire off this job in coroutine. Workers that remain active after
                // an empty pop will sleep before checking the queue again.
                $job = $popWaiter->wait(fn (): ?JobContract => $this->withCoroutineContext(
                    $options,
                    function () use ($connectionName, $queue, $options): ?JobContract {
                        $job = $this->getNextJob(
                            $this->manager->connection($connectionName),
                            $queue,
                        );

                        if ($job === null && $this->events->hasListeners(WorkerIdle::class)) {
                            $this->events->dispatch(new WorkerIdle($connectionName, $queue, $options));
                        }

                        return $job;
                    },
                ));
                if ($job) {
                    ++$jobsAdmitted;
                    $concurrent->create(function () use ($job, $connectionName, $options): void {
                        try {
                            $this->runJob($job, $connectionName, $options);
                        } finally {
                            ++$this->jobsProcessed;
                            $this->lastJobProcessedAt = $this->currentTime();
                        }
                    });

                    if ($options->rest > 0) {
                        $this->sleep($options->rest);
                    }
                } else {
                    if (! $options->stopWhenEmpty) {
                        $this->sleep($options->sleep);
                    }
                }

                // Finally, we will check to see if we have exceeded our memory limits or if
                // the queue should restart based on other indications. If so, we'll stop
                // this worker and let whatever is "monitoring" it restart the process.
                /** @var null|array{0: int, 1: WorkerStopReason} $stop */
                $stop = $lifecycleWaiter->wait(fn (): ?array => $this->withCoroutineContext(
                    $options,
                    fn (): ?array => $this->stopIfNecessary(
                        $options,
                        $lastRestart,
                        $startTime,
                        $jobsAdmitted,
                        $job,
                        hasRunningJobs: ! $concurrent->isEmpty(),
                    ),
                ));

                if ($stop !== null) {
                    [$status, $reason] = $stop;

                    $this->waitForRunningJobs($concurrent);

                    return $this->stop($status, $options, $reason, $connectionName, $queue);
                }
            }
        } finally {
            try {
                $concurrent->cancel();
            } finally {
                if ($this->monitorId !== null) {
                    $this->timer->clear($this->monitorId);
                    $this->monitorId = null;
                }
            }
        }
    }

    /**
     * Create the waiter used to pop jobs.
     */
    protected function createPopWaiter(): Waiter
    {
        return new Waiter(-1);
    }

    /**
     * Wait for every admitted job coroutine to finish.
     */
    protected function waitForRunningJobs(WaitConcurrent $concurrent): void
    {
        $lifecycleWaiter = new Waiter(-1);

        do {
            $this->drainPendingSignals($lifecycleWaiter);
        } while (! $concurrent->wait(self::SHUTDOWN_WAIT_SECONDS));

        $this->drainPendingSignals($lifecycleWaiter);
    }

    /**
     * Deliver pending signals outside their asynchronous handlers.
     */
    protected function drainPendingSignals(Waiter $lifecycleWaiter): void
    {
        while (($signal = array_shift($this->pendingSignals)) !== null) {
            $lifecycleWaiter->wait(fn (): mixed => $this->withCoroutineContext(
                $signal['options'],
                function () use ($signal): void {
                    if ($signal['signal'] === SIGUSR2) {
                        if ($this->events->hasListeners(WorkerPausing::class)) {
                            $this->events->dispatch(new WorkerPausing(
                                $signal['connectionName'],
                                $signal['queue'],
                                $signal['options'],
                            ));
                        }

                        return;
                    }

                    if ($signal['signal'] === SIGCONT) {
                        if ($this->events->hasListeners(WorkerResuming::class)) {
                            $this->events->dispatch(new WorkerResuming(
                                $signal['connectionName'],
                                $signal['queue'],
                                $signal['options'],
                            ));
                        }

                        return;
                    }

                    if ($this->events->hasListeners(WorkerInterrupted::class)) {
                        $this->events->dispatch(new WorkerInterrupted(
                            $signal['signal'],
                            $signal['connectionName'],
                            $signal['queue'],
                            $signal['options'],
                        ));
                    }

                    $this->notifyJobsOfSignal($signal['signal']);
                },
            ));
        }
    }

    /**
     * Monitor the jobs for timeout.
     */
    protected function monitorTimeoutJobs(
        WorkerOptions $options,
        ?string $connectionName = null,
        ?string $queue = null,
    ): void {
        if ($this->monitorId !== null) {
            return;
        }

        $lifecycleWaiter = new Waiter(-1);
        $this->monitorId = $this->timer->tick($options->monitorInterval, function () use ($lifecycleWaiter, $options, $connectionName, $queue): void {
            $lifecycleWaiter->wait(fn (): mixed => $this->withCoroutineContext($options, function () use ($options, $connectionName, $queue): void {
                if ($this->monitorLocked) {
                    return;
                }

                $this->monitorLocked = true;

                try {
                    $this->terminateTimeoutJobs($options);

                    if ($this->hasTimeoutJobs()) {
                        $this->shouldQuit = true;
                        $this->kill(
                            static::$timeoutExceededExitCode ?? static::EXIT_ERROR,
                            $options,
                            WorkerStopReason::TimedOut,
                            $connectionName,
                            $queue,
                        );
                    }
                } finally {
                    $this->monitorLocked = false;
                }
            }));
        });
    }

    /**
     * Scanning the running jobs and terminate the timeout jobs.
     */
    protected function terminateTimeoutJobs(WorkerOptions $options): void
    {
        $currentTime = $this->currentTime();
        foreach ($this->runningJobs as $jobId => $job) {
            if ($job['expires_at'] !== null && $job['expires_at'] <= $currentTime) {
                $this->timeoutJobIds[] = $jobId;
                unset($this->runningJobs[$jobId]);
                $this->handleTimeoutJob($job['job'], $options);
            }
        }
    }

    /**
     * Determine if there are any timeout jobs.
     */
    protected function hasTimeoutJobs(): bool
    {
        return (bool) count($this->timeoutJobIds);
    }

    protected function handleTimeoutJob(JobContract $job, WorkerOptions $options): void
    {
        $this->markJobAsFailedIfWillExceedMaxAttempts(
            $job->getConnectionName(),
            $job,
            (int) $options->maxTries,
            $e = $this->timeoutExceededException($job)
        );

        $this->markJobAsFailedIfWillExceedMaxExceptions(
            $job->getConnectionName(),
            $job,
            $e
        );

        $this->markJobAsFailedIfItShouldFailOnTimeout(
            $job->getConnectionName(),
            $job,
            $e
        );

        if ($this->events->hasListeners(JobTimedOut::class)) {
            $this->events->dispatch(new JobTimedOut(
                $job->getConnectionName(),
                $job
            ));
        }
    }

    /**
     * Get the appropriate timeout for the given job.
     */
    protected function timeoutForJob(?JobContract $job, WorkerOptions $options): int
    {
        return $job && ! is_null($job->timeout()) ? $job->timeout() : $options->timeout;
    }

    /**
     * Determine if the daemon should process on this iteration.
     */
    protected function daemonShouldRun(WorkerOptions $options, string $connectionName, string $queue): bool
    {
        return ! ((($this->isDownForMaintenance)() && ! $options->force)
            || $this->paused
            || ($this->events->hasListeners(Looping::class)
                && $this->events->until(new Looping($connectionName, $queue, $options)) === false));
    }

    /**
     * Pause the worker for the current loop.
     */
    protected function pauseWorker(
        WorkerOptions $options,
        ?int $lastRestart,
        float|int $startTime,
        int $jobsAdmitted,
    ): ?array {
        $this->sleep($options->sleep > 0 ? $options->sleep : 1);

        return $this->stopIfNecessary(
            $options,
            $lastRestart,
            $startTime,
            $jobsAdmitted,
            checkQueueEmpty: false,
        );
    }

    /**
     * Determine the exit code to stop the process if necessary.
     */
    protected function stopIfNecessary(
        WorkerOptions $options,
        ?int $lastRestart,
        float|int $startTime,
        int $jobsAdmitted,
        mixed $job = null,
        bool $checkQueueEmpty = true,
        bool $hasRunningJobs = false,
    ): ?array {
        return match (true) {
            $this->stopReason !== null => [static::EXIT_SUCCESS, $this->stopReason],
            $this->shouldQuit => [static::EXIT_SUCCESS, WorkerStopReason::Interrupted],
            $this->memoryExceeded($options->memory) => [static::$memoryExceededExitCode ?? static::EXIT_MEMORY_LIMIT, WorkerStopReason::MaxMemoryExceeded],
            $this->queueShouldRestart($lastRestart) => [static::EXIT_SUCCESS, WorkerStopReason::ReceivedRestartSignal],
            $checkQueueEmpty && $options->stopWhenEmpty && is_null($job) => [static::EXIT_SUCCESS, WorkerStopReason::QueueEmpty],
            $checkQueueEmpty
                && $options->stopWhenEmptyFor
                && is_null($job)
                && ! $hasRunningJobs
                && $this->currentTime() - ($this->lastJobProcessedAt ?? $startTime) >= $options->stopWhenEmptyFor => [static::EXIT_SUCCESS, WorkerStopReason::QueueEmptyFor],
            $options->maxTime && $this->currentTime() - $startTime >= $options->maxTime => [static::EXIT_SUCCESS, WorkerStopReason::MaxTimeExceeded],
            $options->maxJobs && $jobsAdmitted >= $options->maxJobs => [static::EXIT_SUCCESS, WorkerStopReason::MaxJobsExceeded],
            default => null,
        };
    }

    /**
     * Process the next job on the queue.
     */
    public function runNextJob(string $connectionName, string $queue, WorkerOptions $options): null
    {
        $job = $this->getNextJob(
            $this->manager->connection($connectionName),
            $queue
        );

        // If we're able to pull a job off of the stack, we will process it and then return
        // from this method. If there is no job on the queue, we will "sleep" the worker
        // for the specified number of seconds, then keep processing jobs after sleep.
        if ($job) {
            return $this->runJob($job, $connectionName, $options);
        }

        $this->sleep($options->sleep);

        return null;
    }

    /**
     * Get the next job from the queue connection.
     */
    protected function getNextJob(QueueContract $connection, string $queue): ?JobContract
    {
        $popJobCallback = function ($queue, $index = 0) use ($connection) {
            return $connection instanceof IndexAwareQueue
                ? $connection->pop($queue, $index)
                : $connection->pop($queue);
        };

        $this->raiseBeforeJobPopEvent($connection->getConnectionName(), $queue);

        try {
            if (isset(static::$popCallbacks[$this->name ?? ''])) {
                if (! is_null($job = (static::$popCallbacks[$this->name ?? ''])($popJobCallback, $queue))) {
                    $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);
                }

                return $job;
            }

            $queues = explode(',', $queue);
            $paused = array_flip($this->getPausedQueues($connection->getConnectionName(), $queues));

            foreach ($queues as $index => $queue) {
                if (isset($paused[$queue])) {
                    continue;
                }

                if (! is_null($job = $popJobCallback($queue, $index))) {
                    $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);

                    return $job;
                }
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            $this->stopWorkerIfLostConnection($e);

            $this->sleep(1);
        }

        return null;
    }

    /**
     * Determine which of the given queues are currently paused.
     */
    protected function getPausedQueues(string $connectionName, array $queues): array
    {
        if (! static::$pausable) {
            return [];
        }

        if ($this->cache === null) {
            return [];
        }

        /** @var \Hypervel\Queue\QueueManager $manager */
        $manager = $this->manager;

        return $manager->getPausedQueues($connectionName, $queues);
    }

    /**
     * Process the given job.
     */
    protected function runJob(JobContract $job, string $connectionName, WorkerOptions $options): null
    {
        return $this->withCoroutineContext($options, function () use ($job, $connectionName, $options) {
            try {
                $this->process($connectionName, $job, $options);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $e) {
                if (static::$reportJobExceptions) {
                    $this->exceptions->report($e);
                }

                $this->stopWorkerIfLostConnection($e);
            }

            return null;
        });
    }

    /**
     * Run the callback with the worker option context active.
     */
    protected function withCoroutineContext(WorkerOptions $options, callable $callback): mixed
    {
        $contextValues = $options->coroutineContext;
        $previousContextValues = [];
        $previousContextExists = [];

        // Daemon jobs and monitor ticks run in child coroutines, so command/output
        // context has to be seeded explicitly while worker lifecycle events run.
        foreach ($contextValues as $key => $value) {
            $previousContextExists[$key] = CoroutineContext::has($key);
            $previousContextValues[$key] = CoroutineContext::get($key);

            CoroutineContext::set($key, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($contextValues as $key => $_) {
                if ($previousContextExists[$key]) {
                    CoroutineContext::set($key, $previousContextValues[$key]);
                } else {
                    CoroutineContext::forget($key);
                }
            }
        }
    }

    /**
     * Stop the worker if we have lost connection to a database.
     */
    protected function stopWorkerIfLostConnection(Throwable $e): void
    {
        if (static::$stopOnLostConnection && $this->causedByLostConnection($e)) {
            $this->stopReason = WorkerStopReason::LostConnection;
        }
    }

    /**
     * Process the given job from the queue.
     *
     * @throws Throwable
     */
    public function process(string $connectionName, JobContract $job, WorkerOptions $options): void
    {
        $runningJobId = null;
        $invalidPayloadException = null;
        $canceled = false;

        try {
            try {
                $job->payload();
            } catch (InvalidPayloadException $e) {
                $exceptionOccurred = $invalidPayloadException = $e;
                $this->handleInvalidPayload($connectionName, $job, $e);

                return;
            }

            // First we will raise the before job event and determine if the job has already run
            // over its maximum attempt limits, which could primarily happen when this job is
            // continually timing out and not actually throwing any exceptions from itself.
            $this->raiseBeforeJobEvent($connectionName, $job);

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connectionName,
                $job,
                (int) $options->maxTries
            );

            if ($job->isDeleted()) {
                $this->raiseAfterJobEvent($connectionName, $job);
                return;
            }

            // Next we will register this job to running jobs for timeout monitoring.
            $runningJobId = $this->registerCoroutineJob($job, $options);

            // Here we will fire off the job and let it process. We will catch any exceptions, so
            // they can be reported to the developer's logs, etc. Once the job is finished the
            // proper events will be fired to let any listeners know this job has completed.
            $job->fire();

            // If the job has timed out, we will raise the timeout event and mark the job as failed.
            if (in_array($runningJobId, $this->timeoutJobIds, strict: true)) {
                return;
            }

            $this->raiseAfterJobEvent($connectionName, $job);
        } catch (CanceledException $exception) {
            $canceled = true;

            throw $exception;
        } catch (Throwable $e) {
            $exceptionOccurred = $e;

            try {
                $this->handleJobException($connectionName, $job, $options, $e);
            } catch (CanceledException $exception) {
                $canceled = true;

                throw $exception;
            }
        } finally {
            if ($runningJobId) {
                unset($this->runningJobs[$runningJobId]);
            }

            if (! $canceled) {
                if ($this->events->hasListeners(JobAttempted::class)) {
                    $this->events->dispatch(new JobAttempted(
                        $connectionName,
                        $job,
                        $exceptionOccurred ?? null
                    ));
                }

                if ($invalidPayloadException !== null && static::$reportJobExceptions) {
                    $this->exceptions->report($invalidPayloadException);
                }
            }
        }
    }

    /**
     * Fail a job whose payload cannot be consumed.
     */
    protected function handleInvalidPayload(string $connectionName, JobContract $job, InvalidPayloadException $e): void
    {
        $this->raiseExceptionOccurredJobEvent($connectionName, $job, $e);
        $job->fail($e);
    }

    /**
     * Register a coroutine job to running jobs.
     */
    protected function registerCoroutineJob(JobContract $job, WorkerOptions $options): string
    {
        $this->runningJobs[$jobId = Str::uuid()->toString()] = [
            'job' => $job,
            'expires_at' => ($timeout = $this->timeoutForJob($job, $options)) > 0
                ? $this->currentTime() + $timeout
                : null,
        ];

        return $jobId;
    }

    /**
     * Handle an exception that occurred while the job was running.
     *
     * @throws Throwable
     */
    protected function handleJobException(string $connectionName, JobContract $job, WorkerOptions $options, Throwable $e): void
    {
        $canceled = false;

        try {
            // First, we will go ahead and mark the job as failed if it will exceed the maximum
            // attempts it is allowed to run the next time we process it. If so we will just
            // go ahead and mark it as failed now so we do not have to release this again.
            if (! $job->hasFailed()) {
                $this->markJobAsFailedIfWillExceedMaxAttempts(
                    $connectionName,
                    $job,
                    (int) $options->maxTries,
                    $e
                );

                $this->markJobAsFailedIfWillExceedMaxExceptions(
                    $connectionName,
                    $job,
                    $e
                );

                $this->markJobAsFailedIfItShouldntBeRetried(
                    $connectionName,
                    $job,
                    $e
                );
            }

            $this->raiseExceptionOccurredJobEvent(
                $connectionName,
                $job,
                $e
            );
        } catch (CanceledException $exception) {
            $canceled = true;

            throw $exception;
        } finally {
            // If we catch an exception, we will attempt to release the job back onto the queue
            // so it is not lost entirely. This'll let the job be retried at a later time by
            // another listener (or this same one). We will re-throw this exception after.
            if (! $canceled && ! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
                $backoff = $this->calculateBackoff($job, $options);

                $job->release($backoff);

                if ($this->events->hasListeners(JobReleasedAfterException::class)) {
                    $this->events->dispatch(new JobReleasedAfterException(
                        $connectionName,
                        $job,
                        $backoff,
                        $e,
                    ));
                }
            }
        }

        throw $e;
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     *
     * This will likely be because the job previously exceeded a timeout.
     *
     * @throws Throwable
     */
    protected function markJobAsFailedIfAlreadyExceedsMaxAttempts(string $connectionName, JobContract $job, int $maxTries): void
    {
        $maxTries = ! is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        $retryUntil = $job->retryUntil();

        if ($retryUntil && CarbonImmutable::now()->getTimestamp() <= $retryUntil) {
            return;
        }

        if (! $retryUntil && ($maxTries === 0 || $job->attempts() <= $maxTries)) {
            return;
        }

        $this->failJob($job, $e = $this->maxAttemptsExceededException($job));

        throw $e;
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     */
    protected function markJobAsFailedIfWillExceedMaxAttempts(string $connectionName, JobContract $job, int $maxTries, Throwable $e): void
    {
        $maxTries = ! is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        if ($job->retryUntil() && $job->retryUntil() <= CarbonImmutable::now()->getTimestamp()) {
            $this->failJob($job, $e);
        }

        if (! $job->retryUntil() && $maxTries > 0 && $job->attempts() >= $maxTries) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     */
    protected function markJobAsFailedIfWillExceedMaxExceptions(string $connectionName, JobContract $job, Throwable $e): void
    {
        if (! $this->cache || is_null($uuid = $job->uuid())
            || is_null($maxExceptions = $job->maxExceptions())
        ) {
            return;
        }

        if (! $this->cache->get('job-exceptions:' . $uuid)) {
            $this->cache->put('job-exceptions:' . $uuid, 0, CarbonImmutable::now()->addDay());
        }

        $exceptions = $this->cache->increment('job-exceptions:' . $uuid);

        if (is_int($exceptions) && $maxExceptions <= $exceptions) {
            $this->cache->forget('job-exceptions:' . $uuid);

            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed if the exception handler determines it should not be retried.
     */
    protected function markJobAsFailedIfItShouldntBeRetried(string $connectionName, JobContract $job, Throwable $e): void
    {
        if (method_exists($this->exceptions, 'shouldStopRetries')
            && $this->exceptions->shouldStopRetries($e)) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed if it should fail on timeouts.
     */
    protected function markJobAsFailedIfItShouldFailOnTimeout(string $connectionName, JobContract $job, Throwable $e): void
    {
        if (method_exists($job, 'shouldFailOnTimeout') ? $job->shouldFailOnTimeout() : false) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed and raise the relevant event.
     */
    protected function failJob(JobContract $job, Throwable $e): void
    {
        $job->fail($e);
    }

    /**
     * Calculate the backoff for the given job.
     */
    protected function calculateBackoff(JobContract $job, WorkerOptions $options): int
    {
        $backoff = method_exists($job, 'backoff') && ! is_null($job->backoff())
            ? $job->backoff()
            : $options->backoff;

        $backoff = explode(',', (string) $backoff);

        return (int) ($backoff[$job->attempts() - 1] ?? last($backoff));
    }

    /**
     * Raise an event indicating the worker is starting.
     */
    protected function raiseWorkerStartingEvent(string $connectionName, string $queue, WorkerOptions $options): void
    {
        if ($this->events->hasListeners(WorkerStarting::class)) {
            $this->events->dispatch(new WorkerStarting($connectionName, $queue, $options));
        }
    }

    /**
     * Raise the before job has been popped.
     */
    protected function raiseBeforeJobPopEvent(string $connectionName, ?string $queue = null): void
    {
        if ($this->events->hasListeners(JobPopping::class)) {
            $this->events->dispatch(new JobPopping($connectionName, $queue));
        }
    }

    /**
     * Raise the after job has been popped.
     */
    protected function raiseAfterJobPopEvent(string $connectionName, ?JobContract $job): void
    {
        if ($this->events->hasListeners(JobPopped::class)) {
            $this->events->dispatch(new JobPopped(
                $connectionName,
                $job
            ));
        }
    }

    /**
     * Raise the before queue job event.
     */
    protected function raiseBeforeJobEvent(string $connectionName, ?JobContract $job): void
    {
        if ($this->events->hasListeners(JobProcessing::class)) {
            $this->events->dispatch(new JobProcessing(
                $connectionName,
                $job
            ));
        }
    }

    /**
     * Raise the after queue job event.
     */
    protected function raiseAfterJobEvent(string $connectionName, JobContract $job): void
    {
        if ($this->events->hasListeners(JobProcessed::class)) {
            $this->events->dispatch(new JobProcessed(
                $connectionName,
                $job
            ));
        }
    }

    /**
     * Raise the exception occurred queue job event.
     */
    protected function raiseExceptionOccurredJobEvent(string $connectionName, ?JobContract $job, Throwable $e): void
    {
        if ($this->events->hasListeners(JobExceptionOccurred::class)) {
            $this->events->dispatch(new JobExceptionOccurred(
                $connectionName,
                $job,
                $e
            ));
        }
    }

    /**
     * Determine if the queue worker should restart.
     */
    protected function queueShouldRestart(?int $lastRestart): bool
    {
        if (! static::$restartable) {
            return false;
        }

        return $this->getTimestampOfLastQueueRestart() !== $lastRestart;
    }

    /**
     * Get the last queue restart timestamp, or null.
     */
    protected function getTimestampOfLastQueueRestart(): ?int
    {
        if (! static::$restartable) {
            return null;
        }

        if ($this->cache) {
            $timestamp = $this->cache->get(self::RESTART_SIGNAL_CACHE_KEY);

            return is_null($timestamp) ? null : (int) $timestamp;
        }

        return null;
    }

    /**
     * Enable async signals for the process.
     */
    protected function listenForSignals(string $connectionName, string $queue, WorkerOptions $options): void
    {
        // queue:work owns PCNTL signals in its console process. A Swoole server process
        // must use SignalManager instead; registering both would create competing consumers.
        pcntl_async_signals(true);

        foreach ([SIGQUIT, SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, fn (int $signal) => $this->handleInterruptionSignal(
                $signal,
                $connectionName,
                $queue,
                $options
            ));
        }

        pcntl_signal(SIGUSR2, fn () => $this->handlePauseSignal($connectionName, $queue, $options));
        pcntl_signal(SIGCONT, fn () => $this->handleResumeSignal($connectionName, $queue, $options));

        if (! pcntl_sigprocmask(SIG_UNBLOCK, self::HANDLED_SIGNALS)) {
            throw new RuntimeException('Unable to unblock queue worker signals.');
        }
    }

    /**
     * Handle a worker interruption signal.
     */
    protected function handleInterruptionSignal(int $signal, string $connectionName, string $queue, WorkerOptions $options): void
    {
        $this->shouldQuit = true;
        $this->pendingSignals[] = compact('signal', 'connectionName', 'queue', 'options');
    }

    /**
     * Handle a worker pause signal.
     */
    protected function handlePauseSignal(string $connectionName, string $queue, WorkerOptions $options): void
    {
        $this->paused = true;
        $this->pendingSignals[] = [
            'signal' => SIGUSR2,
            'connectionName' => $connectionName,
            'queue' => $queue,
            'options' => $options,
        ];
    }

    /**
     * Handle a worker resume signal.
     */
    protected function handleResumeSignal(string $connectionName, string $queue, WorkerOptions $options): void
    {
        $this->paused = false;
        $this->pendingSignals[] = [
            'signal' => SIGCONT,
            'connectionName' => $connectionName,
            'queue' => $queue,
            'options' => $options,
        ];
    }

    /**
     * Pass the signal to the running jobs.
     */
    protected function notifyJobsOfSignal(int $signal): void
    {
        foreach ($this->runningJobs as $runningJob) {
            /** @var JobContract $job */
            $job = $runningJob['job'];

            $getResolvedJob = [$job, 'getResolvedJob'];

            if (! is_callable($getResolvedJob)) {
                continue;
            }

            $handler = $getResolvedJob();

            if (! $handler instanceof CallQueuedHandler) {
                continue;
            }

            $command = $handler->getRunningCommand();

            if ($command instanceof Interruptible) {
                $command->interrupted($signal);

                if ($this->events->hasListeners(JobInterrupted::class)) {
                    $this->events->dispatch(new JobInterrupted(
                        $job->getConnectionName(),
                        $job,
                        $signal,
                    ));
                }
            }
        }
    }

    /**
     * Determine if "async" signals are supported.
     */
    protected function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * Determine if the memory limit has been exceeded.
     */
    public function memoryExceeded(float $memoryLimit): bool
    {
        return $memoryLimit > 0 && $this->currentMemoryUsage() >= $memoryLimit;
    }

    /**
     * Get the current memory usage in megabytes.
     */
    protected function currentMemoryUsage(): float
    {
        return memory_get_usage(true) / 1024 / 1024;
    }

    /**
     * Get the current monotonic time.
     */
    protected function currentTime(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Stop listening and bail out of the script.
     */
    public function stop(
        int $status = 0,
        ?WorkerOptions $options = null,
        ?WorkerStopReason $reason = null,
        ?string $connectionName = null,
        ?string $queue = null,
    ): int {
        if ($this->events->hasListeners(WorkerStopping::class)) {
            $this->events->dispatch(new WorkerStopping(
                $status,
                $options,
                $reason,
                $this->jobsProcessed,
                $this->lastJobProcessedAt,
                $this->currentMemoryUsage(),
                $connectionName,
                $queue,
                terminatesImmediately: false,
            ));
        }

        return $status;
    }

    /**
     * Kill the process.
     */
    public function kill(
        int $status = 0,
        ?WorkerOptions $options = null,
        ?WorkerStopReason $reason = null,
        ?string $connectionName = null,
        ?string $queue = null,
    ): never {
        if ($this->events->hasListeners(WorkerStopping::class)) {
            $this->events->dispatch(new WorkerStopping(
                $status,
                $options,
                $reason,
                $this->jobsProcessed,
                $this->lastJobProcessedAt,
                $this->currentMemoryUsage(),
                $connectionName,
                $queue,
                terminatesImmediately: true,
            ));
        }

        $this->terminateProcess($status);
    }

    /**
     * Terminate the current worker process immediately.
     */
    protected function terminateProcess(int $status): never
    {
        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    /**
     * Create an instance of MaxAttemptsExceededException.
     */
    protected function maxAttemptsExceededException(JobContract $job): MaxAttemptsExceededException
    {
        return MaxAttemptsExceededException::forJob($job);
    }

    /**
     * Create an instance of TimeoutExceededException.
     */
    protected function timeoutExceededException(?JobContract $job): TimeoutExceededException
    {
        return TimeoutExceededException::forJob($job);
    }

    /**
     * Sleep the script for a given number of seconds.
     */
    public function sleep(float|int $seconds): void
    {
        Sleep::usleep((int) ($seconds * 1000000));
    }

    /**
     * Set the cache repository implementation.
     *
     * Boot-only. The repository persists on the singleton worker for the
     * worker lifetime and is read by every subsequent pause or restart check.
     */
    public function setCache(CacheContract $cache): static
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Set the name of the worker.
     *
     * Boot-only. The name persists on the singleton worker for the worker
     * lifetime and selects the static pop callback used by every job pop.
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Register a callback to be executed to pick jobs.
     *
     * Boot-only. The callback persists in a static property for the worker
     * process lifetime and runs on every job pop for the named worker. Passing
     * null removes the callback.
     */
    public static function popUsing(string $workerName, ?callable $callback): void
    {
        if (is_null($callback)) {
            unset(static::$popCallbacks[$workerName]);
        } else {
            static::$popCallbacks[$workerName] = $callback;
        }
    }

    /**
     * Get the queue manager instance.
     */
    public function getManager(): QueueManager
    {
        return $this->manager;
    }

    /**
     * Set the queue manager instance.
     *
     * Tests only. Swaps the manager reference on the singleton worker; runtime
     * use races across coroutines and changes every concurrent queue lookup.
     */
    public function setManager(QueueManager $manager): void
    {
        $this->manager = $manager;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$popCallbacks = [];
        static::$memoryExceededExitCode = null;
        static::$timeoutExceededExitCode = null;
        static::$reportJobExceptions = true;
        static::$stopOnLostConnection = true;
        static::$restartable = true;
        static::$pausable = true;
    }
}
