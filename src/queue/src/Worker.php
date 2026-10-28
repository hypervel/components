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
use Hypervel\Contracts\Queue\JobLifecycleCallbacks;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\DetectsLostConnections;
use Hypervel\Database\LostConnectionException;
use Hypervel\Database\QueryException;
use Hypervel\Engine\Coroutine;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobInterrupted;
use Hypervel\Queue\Events\JobPopped;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleased;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Queue\Events\Looping;
use Hypervel\Queue\Events\WorkerIdle;
use Hypervel\Queue\Events\WorkerInterrupted;
use Hypervel\Queue\Events\WorkerPausing;
use Hypervel\Queue\Events\WorkerQueuePaused;
use Hypervel\Queue\Events\WorkerQueueResumed;
use Hypervel\Queue\Events\WorkerResuming;
use Hypervel\Queue\Events\WorkerStarting;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\QueueManager as ConcreteQueueManager;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use LogicException;
use PDOException;
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
     *
     * @var array<string, float>
     */
    protected array $timeoutJobIds = [];

    /**
     * Notifications that may still publish a timed-out attempt's outcome.
     *
     * @var array<string, WaitGroup>
     */
    protected array $timeoutNotifications = [];

    /**
     * The job monitor's ID.
     */
    protected ?int $monitorId = null;

    /**
     * The independent event-loop liveness timer.
     */
    protected ?int $livenessTimerId = null;

    /**
     * The last time the event loop made progress.
     */
    protected float $lastLoopProgressAt = 0;

    /**
     * Indicates whether this daemon owns the timeout alarm.
     */
    protected bool $ownsTimeoutAlarm = false;

    /**
     * The deadline currently armed with PCNTL.
     */
    protected ?float $timeoutAlarmAt = null;

    /**
     * Indicates that blocked-loop recovery has committed to terminating the worker.
     */
    protected bool $recoveringBlockedLoop = false;

    /**
     * The coroutine frozen by emergency recovery.
     */
    protected ?int $parkedCoroutineId = null;

    /**
     * The timer that owns the timeout monitor.
     */
    protected Timer $timer;

    /**
     * The number of jobs completed by the worker.
     */
    protected int $jobsProcessed = 0;

    /**
     * The timestamp of the last completed job.
     */
    protected ?float $lastJobProcessedAt = null;

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
     * Indicates if the worker lost its connection.
     */
    public bool $lostConnection = false;

    /**
     * Indicates if the worker is paused.
     */
    public bool $paused = false;

    /**
     * The queues the worker last observed to be paused.
     *
     * @var array<int, string>
     */
    protected array $pausedQueues = [];

    /**
     * The connection used for the last pause-state observation.
     */
    protected ?string $lastPolledConnection = null;

    /**
     * The queue list used for the last pause-state observation.
     */
    protected ?string $lastPolledQueues = null;

    /**
     * The callbacks used to pop jobs from queues.
     *
     * @var callable[]
     */
    protected static array $popCallbacks = [];

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
    public static ?int $timedOutExitCode = null;

    /**
     * Indicates if the worker should be killed when a job exceeds its timeout.
     *
     * Boot-only. Mutates the timeout policy shared by every job in the worker.
     */
    public static bool $killOnTimeout = true;

    /**
     * The callback used to kill the worker process.
     *
     * @var null|(callable(int): mixed)
     */
    protected static mixed $killCallback = null;

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
        $this->lostConnection = false;

        // A new daemon run must report initially paused queues even when this worker is reused.
        $this->pausedQueues = [];
        $this->lastPolledConnection = null;
        $this->lastPolledQueues = null;

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

        try {
            $this->startTimeoutAlarm($options);
            $this->monitorTimeoutJobs($options, $connectionName, $queue);

            while (true) {
                if ($this->recoveringBlockedLoop || (static::$killOnTimeout && $this->hasTimeoutJobs())) {
                    $this->sleep(self::SHUTDOWN_WAIT_SECONDS);

                    continue;
                }

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

                        return $this->stop($connectionName, $queue, $status, $options, $reason);
                    }

                    continue;
                }

                // If there are timeout jobs or the concurrency limit is hit, we should
                // not accept new jobs. A full worker waits on capacity so completed jobs
                // wake it immediately instead of limiting throughput to the poll interval.
                $hasTimeoutJobs = static::$killOnTimeout && $this->hasTimeoutJobs();
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

                        return $this->stop($connectionName, $queue, $status, $options, $reason);
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
                if ($this->recoveringBlockedLoop || (static::$killOnTimeout && $this->hasTimeoutJobs())) {
                    // Leave an already reserved job for its normal reservation expiry.
                    continue;
                }

                if ($job) {
                    ++$jobsAdmitted;
                    $concurrent->create(function () use ($job, $connectionName, $options): void {
                        try {
                            $this->runJob($job, $connectionName, $options);
                        } catch (Throwable $exception) {
                            if (! FrameworkCoroutine::causedByCancellation($exception)) {
                                throw $exception;
                            }

                            if (! $exception instanceof CanceledException && static::$reportJobExceptions) {
                                $this->withCoroutineContext($options, fn () => $this->exceptions->report($exception));
                            }
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

                    return $this->stop($connectionName, $queue, $status, $options, $reason);
                }
            }
        } finally {
            try {
                $concurrent->cancel();
            } finally {
                if ($this->ownsTimeoutAlarm && ! $this->recoveringBlockedLoop) {
                    pcntl_alarm(0);
                    pcntl_signal(SIGALRM, SIG_DFL);
                    $this->ownsTimeoutAlarm = false;
                    $this->timeoutAlarmAt = null;
                }

                if ($this->livenessTimerId !== null) {
                    $this->timer->clear($this->livenessTimerId);
                    $this->livenessTimerId = null;
                }

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
            $finished = $concurrent->wait(self::SHUTDOWN_WAIT_SECONDS);

            // A timeout can arrive while a normal shutdown waits for these jobs.
            // Keep the monitor alive until it publishes the timeout outcome and exits.
            if ($finished && static::$killOnTimeout && $this->hasTimeoutJobs()) {
                $this->sleep(self::SHUTDOWN_WAIT_SECONDS);
                $finished = false;
            }
        } while (! $finished);

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
        string $connectionName,
        string $queue,
    ): void {
        if ($this->monitorId !== null) {
            return;
        }

        $this->monitorId = $this->timer->tick($options->monitorInterval, function () use ($options, $connectionName, $queue): void {
            $this->withCoroutineContext($options, function () use ($options, $connectionName, $queue): void {
                $this->terminateTimeoutJobs($options);

                if (static::$killOnTimeout && $this->hasTimeoutJobs()) {
                    $this->shouldQuit = true;
                    $this->kill(
                        $connectionName,
                        $queue,
                        static::$timedOutExitCode ?? static::EXIT_ERROR,
                        $options,
                        WorkerStopReason::TimedOut,
                    );
                }
            });
        });
    }

    /**
     * Allow a full monitor interval plus whole-second alarm granularity.
     */
    protected function timeoutGracePeriod(WorkerOptions $options): int
    {
        return max(1, $options->monitorInterval) + 1;
    }

    /**
     * Recover kill-mode timeouts when PHP code prevents the monitor from running.
     */
    protected function startTimeoutAlarm(WorkerOptions $options): void
    {
        if (! static::$killOnTimeout || ! $this->supportsAsyncSignals()) {
            return;
        }

        $this->ownsTimeoutAlarm = true;
        $this->lastLoopProgressAt = $this->currentTime();
        pcntl_signal(SIGALRM, fn () => $this->handleTimeoutAlarm($options));

        if (! pcntl_sigprocmask(SIG_UNBLOCK, [SIGALRM])) {
            throw new RuntimeException('Unable to unblock the queue worker timeout alarm.');
        }

        // The timeout monitor can itself wait on yielding failure callbacks.
        // Track the loop independently so those callbacks retain time to finish.
        $this->livenessTimerId = $this->timer->tick(max(1, $options->monitorInterval), function (): void {
            $this->lastLoopProgressAt = $this->currentTime();
        });
    }

    /**
     * Arm an earlier timeout check without scanning jobs on the admission path.
     */
    protected function armTimeoutAlarm(float $deadline): void
    {
        if (! $this->ownsTimeoutAlarm || $this->recoveringBlockedLoop
            || ($this->timeoutAlarmAt !== null && $this->timeoutAlarmAt <= $deadline)) {
            return;
        }

        $this->timeoutAlarmAt = $deadline;
        pcntl_alarm(max(1, (int) ceil($deadline - $this->currentTime())));
    }

    /**
     * Give the normal timeout handler a bounded chance to recover a blocked loop.
     */
    protected function handleTimeoutAlarm(WorkerOptions $options): void
    {
        $this->timeoutAlarmAt = null;
        $deadline = null;

        foreach ($this->runningJobs as $job) {
            if ($job['expires_at'] !== null) {
                $deadline = $deadline === null ? $job['expires_at'] : min($deadline, $job['expires_at']);
            }
        }

        foreach ($this->timeoutJobIds as $expiresAt) {
            $deadline = $deadline === null ? $expiresAt : min($deadline, $expiresAt);
        }

        if ($deadline === null) {
            return;
        }

        $grace = $this->timeoutGracePeriod($options);
        $now = $this->currentTime();
        if ($now < $deadline + $grace || $now < $this->lastLoopProgressAt + $grace) {
            $this->armTimeoutAlarm(max($deadline + $grace, $this->lastLoopProgressAt + $grace));

            return;
        }

        $this->recoveringBlockedLoop = true;
        $this->parkedCoroutineId = Coroutine::id();

        // PHP cannot dispatch another signal callback while this one is parked.
        // The kernel's default action still terminates a second stall or blocked cleanup.
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm($grace);

        try {
            if (Coroutine::id() > 0) {
                Coroutine::yield();
            }
        } finally {
            // Never return to the interrupted job if it is resumed or canceled.
            $this->terminateProcess(static::$timedOutExitCode ?? static::EXIT_ERROR);
        }
    }

    /**
     * Scan the running jobs and terminate the timeout jobs.
     */
    protected function terminateTimeoutJobs(WorkerOptions $options): void
    {
        $currentTime = $this->currentTime();
        $expiredJobs = [];

        foreach ($this->runningJobs as $jobId => $job) {
            // Earlier timeout handling may yield while another job finishes.
            if (! isset($this->runningJobs[$jobId]) || isset($this->timeoutJobIds[$jobId])) {
                continue;
            }

            if ($job['expires_at'] !== null && $job['expires_at'] <= $currentTime) {
                $this->timeoutJobIds[$jobId] = $job['expires_at'];

                if (! static::$killOnTimeout) {
                    $this->interruptTimeoutJob($jobId, $job);

                    continue;
                }

                $job['parked'] = $job['coroutine_id'] === $this->parkedCoroutineId;
                $expiredJobs[$jobId] = $job;
            }
        }

        // A parked attempt cannot resume. Handle it before spending its kernel
        // deadline waiting for other attempts to unwind.
        foreach ($expiredJobs as $jobId => $job) {
            if ($job['parked']) {
                $this->notifyTimeoutJob($job['job']);
                $this->handleTimeoutJob($job['job'], $options, $job['processing_marker']);
                unset($expiredJobs[$jobId]);
            }
        }

        $coroutineIds = [];

        foreach ($expiredJobs as $jobId => $job) {
            $this->interruptTimeoutJob($jobId, $job);

            if ($job['coroutine_id'] !== $this->parkedCoroutineId && Coroutine::exists($job['coroutine_id'])) {
                $coroutineIds[] = $job['coroutine_id'];
            }
        }

        if ($coroutineIds !== []) {
            Coroutine::join($coroutineIds, $this->timeoutGracePeriod($options));
        }

        foreach ($expiredJobs as $job) {
            // Cancellation can leave finally blocks running. Never race their
            // release or failure operations after an unsuccessful wait.
            if (Coroutine::exists($job['coroutine_id'])) {
                $this->raiseTimedOutJobEvent($job['job'], $options);
            } else {
                $this->handleTimeoutJob($job['job'], $options, $job['processing_marker']);
            }
        }
    }

    /**
     * Interrupt an expired body without canceling a completed body's deferred cleanup.
     */
    protected function interruptTimeoutJob(string $jobId, array $job): void
    {
        // Create the barrier before notification can yield, so a body finishing meanwhile waits for it.
        $notification = static::$killOnTimeout ? null : ($this->timeoutNotifications[$jobId] = new WaitGroup(1));

        try {
            $this->notifyTimeoutJob($job['job']);
        } finally {
            unset($this->timeoutNotifications[$jobId]);
            $notification?->done();

            if (isset($this->runningJobs[$jobId])
                && $job['coroutine_id'] !== $this->parkedCoroutineId
                && Coroutine::exists($job['coroutine_id'])) {
                // @TODO: Pass TimeoutExceededException as the cancellation reason if Swoole adds cancellation reasons.
                Coroutine::cancelById($job['coroutine_id'], throwException: true);
            }
        }
    }

    /**
     * Notify a job before cancellation unwinds its running command.
     */
    protected function notifyTimeoutJob(JobContract $job): void
    {
        try {
            $this->notifyJobOfSignal($job, SIGALRM);
        } catch (Throwable $exception) {
            $this->reportTimeoutException($exception);
        }
    }

    /**
     * Determine if there are any timeout jobs.
     */
    protected function hasTimeoutJobs(): bool
    {
        return $this->timeoutJobIds !== [];
    }

    /**
     * Handle a timed-out job whose execution has ended or is frozen.
     */
    protected function handleTimeoutJob(JobContract $job, WorkerOptions $options, ?JobProcessingMarker $processingMarker = null): void
    {
        try {
            if (! $job->hasFailed() && ! $job->isDeletedOrReleased()) {
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
            }
        } catch (Throwable $exception) {
            $this->reportTimeoutException($exception);
        }

        try {
            $processingMarker?->clear();
        } catch (Throwable $exception) {
            $this->reportTimeoutException($exception);
        }

        $this->raiseTimedOutJobEvent($job, $options);
    }

    /**
     * Raise the event for an attempt that exceeded its timeout.
     */
    protected function raiseTimedOutJobEvent(JobContract $job, WorkerOptions $options): void
    {
        try {
            if ($this->events->hasListeners(JobTimedOut::class)) {
                $this->events->dispatch(new JobTimedOut(
                    $job->getConnectionName(),
                    $job,
                    $this->timeoutForJob($job, $options)
                ));
            }
        } catch (Throwable $exception) {
            $this->reportTimeoutException($exception);
        }
    }

    /**
     * Report a timeout-handling error without abandoning other expired attempts.
     */
    protected function reportTimeoutException(Throwable $exception): void
    {
        if (FrameworkCoroutine::causedByCancellation($exception)) {
            throw $exception;
        }

        $this->exceptions->report($exception);
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
        return ! ((! $options->force && ($this->isDownForMaintenance)())
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
            $this->lostConnection => [static::EXIT_SUCCESS, WorkerStopReason::LostConnection],
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
        $job = $this->withCoroutineContext(
            $options,
            fn (): ?JobContract => $this->getNextJob(
                $this->manager->connection($connectionName),
                $queue,
            ),
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
            $connectionName = $connection->getConnectionName();
            $paused = $this->getPausedQueues($connectionName, $queues);

            // A different selection says nothing about whether the old queues resumed.
            if ($this->lastPolledConnection !== $connectionName || $this->lastPolledQueues !== $queue) {
                $this->pausedQueues = [];
                $this->lastPolledConnection = $connectionName;
                $this->lastPolledQueues = $queue;
            }

            $this->raisePausedQueueEvents($connectionName, $paused);

            $paused = array_flip($paused);

            foreach ($queues as $index => $queue) {
                if (isset($paused[$queue])) {
                    continue;
                }

                if (! is_null($job = $popJobCallback($queue, $index))) {
                    $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);

                    return $job;
                }
            }
        } catch (Throwable $e) {
            if (FrameworkCoroutine::causedByCancellation($e)) {
                throw $e;
            }

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

        /** @var ConcreteQueueManager $manager */
        $manager = $this->manager;

        return $manager->getPausedQueues($queues, $connectionName);
    }

    /**
     * Raise events for any queues that have been paused or resumed since the last check.
     */
    protected function raisePausedQueueEvents(string $connectionName, array $paused): void
    {
        if ($this->events->hasListeners(WorkerQueuePaused::class)) {
            foreach (array_diff($paused, $this->pausedQueues) as $queue) {
                $this->events->dispatch(new WorkerQueuePaused($connectionName, $queue));
            }
        }

        if ($this->events->hasListeners(WorkerQueueResumed::class)) {
            foreach (array_diff($this->pausedQueues, $paused) as $queue) {
                $this->events->dispatch(new WorkerQueueResumed($connectionName, $queue));
            }
        }

        $this->pausedQueues = $paused;
    }

    /**
     * Process the given job.
     */
    protected function runJob(JobContract $job, string $connectionName, WorkerOptions $options): null
    {
        return $this->withCoroutineContext($options, function () use ($job, $connectionName, $options) {
            try {
                $this->process($connectionName, $job, $options);
            } catch (Throwable $e) {
                if (FrameworkCoroutine::causedByCancellation($e)) {
                    throw $e;
                }

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
        if (! static::$stopOnLostConnection) {
            return;
        }

        // Other transports can report similar errors. Classify database failures and their
        // underlying causes, keeping SQL and bindings out of the default detector's decision.
        for ($exception = $e; $exception !== null; $exception = $exception->getPrevious()) {
            $failure = match (true) {
                $exception instanceof QueryException => $exception->getPrevious(),
                $exception instanceof PDOException, $exception instanceof LostConnectionException => $exception,
                default => null,
            };

            if ($failure !== null && $this->causedByLostConnection($failure)) {
                $this->lostConnection = true;

                return;
            }
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
        $processingMarker = null;

        try {
            try {
                $job->payload();
            } catch (InvalidPayloadException $e) {
                $exceptionOccurred = $invalidPayloadException = $e;
                $this->handleInvalidPayload($connectionName, $job, $e);

                return;
            }

            $processingMarker = $this->prepareJobProcessingMarker($job);

            // First we will raise the before job event and determine if the job has already run
            // over its maximum attempt limits, which could primarily happen when this job is
            // continually timing out and not actually throwing any exceptions from itself.
            $this->raiseBeforeJobEvent($connectionName, $job);

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connectionName,
                $job,
                (int) $options->maxTries
            );

            $this->markJobAsFailedIfAlreadyExceedsMaxExceptions($connectionName, $job);

            if ($job->isDeleted()) {
                $this->raiseAfterJobEvent($connectionName, $job);
                return;
            }

            // Next we will register this job to running jobs for timeout monitoring.
            $runningJobId = $this->registerCoroutineJob($job, $options, $processingMarker);

            // Here we will fire off the job and let it process. We will catch any exceptions, so
            // they can be reported to the developer's logs, etc. Once the job is finished the
            // proper events will be fired to let any listeners know this job has completed.
            $startedAt = $this->currentTime();

            try {
                $job->fire();
            } finally {
                $duration = round(($this->currentTime() - $startedAt) * 1000, 2);

                // Non-kill timeouts interrupt execution, not the worker's subsequent
                // failure handling or completion listeners.
                if (! static::$killOnTimeout) {
                    unset($this->runningJobs[$runningJobId]);

                    // The notification may still release or delete this attempt.
                    if ($notification = $this->timeoutNotifications[$runningJobId] ?? null) {
                        try {
                            $notification->wait();
                        } catch (CanceledException $exception) {
                            $canceled = true;

                            throw $exception;
                        }
                    }
                }
            }

            // A declared timeout must not also report normal completion.
            if (isset($this->timeoutJobIds[$runningJobId])) {
                $exceptionOccurred = $this->timeoutExceededException($job);
                $canceled = static::$killOnTimeout;

                if (! static::$killOnTimeout && ! $job->isDeletedOrReleased() && ! $job->hasFailed()) {
                    // Catching cancellation does not replace the timeout's retry or failure policy.
                    throw $exceptionOccurred;
                }

                return;
            }

            $this->raiseAfterJobEvent($connectionName, $job, $duration);

            if ($job->isReleased() && ! $job->isDeleted() && $this->events->hasListeners(JobReleased::class)) {
                $this->events->dispatch(new JobReleased(
                    $connectionName,
                    $job
                ));
            }
        } catch (Throwable $e) {
            if ($canceled) {
                throw $e;
            }

            if ($runningJobId !== null && isset($this->timeoutJobIds[$runningJobId])) {
                if (static::$killOnTimeout) {
                    $canceled = true;

                    throw $e;
                }

                if (! $e instanceof CanceledException && ! $e instanceof TimeoutExceededException && static::$reportJobExceptions) {
                    $this->exceptions->report($e);
                }

                $e = $this->timeoutExceededException($job);
            } elseif (FrameworkCoroutine::causedByCancellation($e)) {
                $canceled = true;

                throw $e;
            }

            $exceptionOccurred = $e;

            try {
                $this->handleJobException($connectionName, $job, $options, $e);
            } catch (Throwable $exception) {
                $canceled = FrameworkCoroutine::causedByCancellation($exception);

                throw $exception;
            }
        } finally {
            $timedOut = $runningJobId !== null && isset($this->timeoutJobIds[$runningJobId]);

            if ($runningJobId) {
                unset($this->runningJobs[$runningJobId]);

                if (! static::$killOnTimeout) {
                    unset($this->timeoutJobIds[$runningJobId]);
                }
            }

            if (! $canceled) {
                $processingMarker?->clear();

                if ($timedOut && ! static::$killOnTimeout) {
                    $this->raiseTimedOutJobEvent($job, $options);
                }

                if ($this->events->hasListeners(JobAttempted::class)) {
                    $this->events->dispatch(new JobAttempted(
                        $connectionName,
                        $job,
                        $exceptionOccurred ?? null,
                        $duration ?? null
                    ));
                }

                if ($invalidPayloadException !== null && static::$reportJobExceptions) {
                    $this->exceptions->report($invalidPayloadException);
                }
            }
        }
    }

    /**
     * Prepare cleanup shared by the job's handoffs and its timeout monitor.
     */
    protected function prepareJobProcessingMarker(JobContract $job): ?JobProcessingMarker
    {
        if (! $this->shouldCountCrashesAsExceptions($job)) {
            return null;
        }

        if (! $job instanceof JobLifecycleCallbacks) {
            $this->failJob($job, $exception = new LogicException(sprintf(
                'Queue job [%s] must implement [%s] to count crashes as exceptions.',
                $job::class,
                JobLifecycleCallbacks::class,
            )));

            throw $exception;
        }

        $marker = new JobProcessingMarker($this->cache, 'job-processing:' . $job->uuid());
        $job->beforeReleasing($marker->clear(...));
        $job->beforeFailing($marker->clear(...));

        return $marker;
    }

    /**
     * Determine whether the job counts interrupted attempts as exceptions.
     *
     * @phpstan-assert-if-true CacheContract $this->cache
     */
    protected function shouldCountCrashesAsExceptions(JobContract $job): bool
    {
        return $this->cache !== null
            && ($job->payload()['countCrashesAsExceptions'] ?? false)
            && $job->uuid() !== null
            && $job->maxExceptions() !== null;
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
    protected function registerCoroutineJob(JobContract $job, WorkerOptions $options, ?JobProcessingMarker $processingMarker = null): string
    {
        $this->runningJobs[$jobId = Str::uuid()->toString()] = [
            'job' => $job,
            'processing_marker' => $processingMarker,
            'coroutine_id' => Coroutine::id(),
            'expires_at' => ($timeout = $this->timeoutForJob($job, $options)) > 0
                ? $this->currentTime() + $timeout
                : null,
        ];

        if ($this->runningJobs[$jobId]['expires_at'] !== null) {
            $this->armTimeoutAlarm($this->runningJobs[$jobId]['expires_at'] + $this->timeoutGracePeriod($options));
        }

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
            // A released attempt no longer owns the reservation; the next delivery
            // enforces its limits without publishing a second outcome here.
            if (! $job->hasFailed() && ! $job->isDeletedOrReleased()) {
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

                if ($e instanceof TimeoutExceededException) {
                    $this->markJobAsFailedIfItShouldFailOnTimeout($connectionName, $job, $e);
                }
            }

            $this->raiseExceptionOccurredJobEvent(
                $connectionName,
                $job,
                $e
            );
        } catch (Throwable $exception) {
            $canceled = FrameworkCoroutine::causedByCancellation($exception);

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
     * Mark the given job as failed if it has exceeded the maximum allowed exceptions.
     *
     * This will likely be because the worker previously died while processing the job.
     *
     * @throws Throwable
     */
    protected function markJobAsFailedIfAlreadyExceedsMaxExceptions(string $connectionName, JobContract $job): void
    {
        // A before-job listener may already have failed or released the job and published a retry.
        if (! $this->shouldCountCrashesAsExceptions($job) || $job->isDeletedOrReleased()) {
            return;
        }

        // If the previous attempt's marker is still present, that attempt never finished...
        if ($this->cache->add('job-processing:' . $job->uuid(), true, CarbonImmutable::now()->addDay())) {
            return;
        }

        $this->markJobAsFailedIfWillExceedMaxExceptions(
            $connectionName,
            $job,
            $exception = $this->maxAttemptsExceededException($job)
        );

        if ($job->hasFailed()) {
            throw $exception;
        }
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
    protected function raiseAfterJobEvent(string $connectionName, JobContract $job, ?float $duration = null): void
    {
        if ($this->events->hasListeners(JobProcessed::class)) {
            $this->events->dispatch(new JobProcessed(
                $connectionName,
                $job,
                $duration
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
            $this->notifyJobOfSignal($runningJob['job'], $signal);
        }
    }

    /**
     * Pass the signal to the given job.
     */
    protected function notifyJobOfSignal(JobContract $job, int $signal): void
    {
        $getResolvedJob = [$job, 'getResolvedJob'];

        if (! is_callable($getResolvedJob)) {
            return;
        }

        $handler = $getResolvedJob();

        if (! $handler instanceof CallQueuedHandler) {
            return;
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
        string $connectionName,
        string $queue,
        int $status = 0,
        ?WorkerOptions $options = null,
        ?WorkerStopReason $reason = null,
    ): int {
        if ($this->events->hasListeners(WorkerStopping::class)) {
            $this->events->dispatch(new WorkerStopping(
                $connectionName,
                $queue,
                $status,
                $options,
                $reason,
                $this->jobsProcessed,
                $this->lastJobProcessedAt,
                $this->currentMemoryUsage(),
                terminatesImmediately: false,
            ));
        }

        return $status;
    }

    /**
     * Kill the process.
     */
    public function kill(
        string $connectionName,
        string $queue,
        int $status = 0,
        ?WorkerOptions $options = null,
        ?WorkerStopReason $reason = null,
    ): never {
        try {
            if ($this->events->hasListeners(WorkerStopping::class)) {
                $this->events->dispatch(new WorkerStopping(
                    $connectionName,
                    $queue,
                    $status,
                    $options,
                    $reason,
                    $this->jobsProcessed,
                    $this->lastJobProcessedAt,
                    $this->currentMemoryUsage(),
                    terminatesImmediately: true,
                ));
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        } finally {
            try {
                if (static::$killCallback) {
                    call_user_func(static::$killCallback, $status);
                }
            } catch (Throwable $exception) {
                $this->exceptions->report($exception);
            } finally {
                // Neither shutdown hooks nor their error reporting may keep the worker alive.
                $this->terminateProcess($status);
            }
        }
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
        Sleep::usleep((int) ($seconds * 1_000_000));
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
     * Register a callback to be used to kill the worker process.
     *
     * Boot-only. The callback persists for the worker lifetime and runs before
     * forced process termination. Passing null clears it.
     *
     * @param null|(callable(int): mixed) $callback
     */
    public static function killUsing(?callable $callback): void
    {
        static::$killCallback = $callback;
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
        static::$timedOutExitCode = null;
        static::$killOnTimeout = true;
        static::$killCallback = null;
        static::$reportJobExceptions = true;
        static::$stopOnLostConnection = true;
        static::$restartable = true;
        static::$pausable = true;
    }
}
