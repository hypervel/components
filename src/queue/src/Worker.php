<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hyperf\Coordinator\Timer;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Database\DetectsLostConnections;
use Hyperf\Stringable\Str;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Queue\Contracts\Factory as QueueManager;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Queue\Contracts\Queue as QueueContract;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobPopped;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Queue\Events\Looping;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Exceptions\MaxAttemptsExceededException;
use Hypervel\Queue\Exceptions\TimeoutExceededException;
use Hypervel\Support\Carbon;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

class Worker
{
    use DetectsLostConnections;

    public const EXIT_SUCCESS = 0;

    public const EXIT_ERROR = 1;

    public const EXIT_MEMORY_LIMIT = 12;

    /**
     * The name of the worker.
     */
    protected ?string $name = null;

    /**
     * The cache repository implementation.
     */
    protected ?CacheFactory $cache = null;

    /**
     * The callback used to determine if the application is in maintenance mode.
     *
     * @var callable
     */
    protected $isDownForMaintenance;

    /**
     * The callback used to reset the application's scope.
     *
     * @var callable
     */
    protected $resetScope;

    /**
     * The callback used to monitor timeout jobs.
     *
     * @var callable
     */
    protected $monitorTimeoutJobs;

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
    protected int $monitorId = 0;

    /**
     * Indicates if the job monitor is checking for timeout jobs.
     */
    protected bool $monitorLocked = false;

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
     * Create a new queue worker.
     *
     * @param QueueManager $manager the queue manager instance
     * @param EventDispatcherInterface $events the event dispatcher instance
     * @param ExceptionHandlerContract $exceptions the exception handler instance
     * @param callable $isDownForMaintenance the callback used to determine if the application is in maintenance mode
     * @param int $monitorInterval the monitor interval
     */
    public function __construct(
        protected QueueManager $manager,
        protected EventDispatcherInterface $events,
        protected ExceptionHandlerContract $exceptions,
        callable $isDownForMaintenance,
        ?callable $monitorTimeoutJobs = null,
        protected int $monitorInterval = 1,
    ) {
        $this->isDownForMaintenance = $isDownForMaintenance;
        $this->monitorTimeoutJobs = $monitorTimeoutJobs;
    }

    /**
     * Listen to the given queue in a loop.
     */
    public function daemon(string $connectionName, string $queue, WorkerOptions $options, ?callable $monitorTimeoutJobs = null): int
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        [$startTime, $jobsProcessed] = [hrtime(true) / 1e9, 0];

        $concurrent = new Concurrent($options->concurrency);

        // Before we begin processing, we will setup the monitor to check for timeout jobs
        $monitorTimeoutJobs
            ? $monitorTimeoutJobs($options)
            : $this->monitorTimeoutJobs($options);

        while (true) {
            // Before reserving any jobs, we will make sure this queue is not paused and
            // if it is we will just pause this worker for a given amount of time and
            // make sure we do not need to kill this worker process off completely.
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                $status = $this->pauseWorker($options, $lastRestart);

                if (! is_null($status)) {
                    return $this->stop($status, $options);
                }

                continue;
            }

            // If there are timeout jobs or the concurrency limit is hit,
            // we should not accept new jobs
            if ($this->hasTimeoutJobs() || $concurrent->isFull()) {
                $this->sleep($options->sleep);
                continue;
            }

            // First, we will attempt to get the next job off of the queue.
            // Then, we can fire off this job in coroutine. If there are no jobs,
            // we will need to sleep the worker so no more jobs are processed
            // until they should be processed.
            $job = $this->getNextJob(
                $this->manager->connection($connectionName),
                $queue
            );
            if ($job) {
                ++$jobsProcessed;
                $concurrent->create(fn () => $this->runJob($job, $connectionName, $options));
            }

            if ($job && $options->rest > 0) {
                $this->sleep($options->rest);
            } elseif (! $job && $jobsProcessed) {
                $this->sleep($options->sleep);
            }

            // Finally, we will check to see if we have exceeded our memory limits or if
            // the queue should restart based on other indications. If so, we'll stop
            // this worker and let whatever is "monitoring" it restart the process.
            $status = $this->stopIfNecessary(
                $options,
                $lastRestart,
                $startTime,
                $jobsProcessed,
                $job
            );

            if (! is_null($status)) {
                return $this->stop($status, $options);
            }
        }
    }

    /**
     * Monitor the jobs for timeout.
     */
    protected function monitorTimeoutJobs(WorkerOptions $options): void
    {
        if ($this->monitorId) {
            return;
        }

        if ($this->monitorTimeoutJobs) {
            ($this->monitorTimeoutJobs)($options);
            return;
        }

        $this->monitorId = (new Timer())->tick($this->monitorInterval, function () use ($options) {
            if ($this->monitorLocked) {
                return;
            }

            $this->monitorLocked = true;

            $this->terminateTimeoutJobs($options);

            if ($this->hasTimeoutJobs()) {
                $this->shouldQuit = true;
                $this->kill(static::EXIT_SUCCESS, $options);
            }

            $this->monitorLocked = false;
        });
    }

    /**
     * Scanning the running jobs and terminate the timeout jobs.
     */
    protected function terminateTimeoutJobs(WorkerOptions $options): void
    {
        $currentTime = microtime(true);
        foreach ($this->runningJobs as $jobId => $job) {
            if ($job['expires_at'] <= $currentTime) {
                $this->timeoutJobIds[] = $jobId;
                unset($this->runningJobs[$jobId]);
                $this->handleTimeoutJob($job['job'], $options);
            }
        }
    }

    /**
     * Determine if there are any active coroutines.
     */
    protected function hasActiveCoroutines(): bool
    {
        return (bool) count($this->runningJobs);
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

        $this->events->dispatch(new JobTimedOut(
            $job->getConnectionName(),
            $job
        ));
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
            || ! tap($this->events->dispatch(new Looping($connectionName, $queue)), fn ($event) => $event->shouldRun()));
    }

    /**
     * Pause the worker for the current loop.
     */
    protected function pauseWorker(WorkerOptions $options, ?int $lastRestart = 0): ?int
    {
        $this->sleep($options->sleep > 0 ? $options->sleep : 1);

        return $this->stopIfNecessary($options, $lastRestart);
    }

    /**
     * Determine the exit code to stop the process if necessary.
     */
    protected function stopIfNecessary(WorkerOptions $options, ?int $lastRestart = 0, float|int $startTime = 0, int $jobsProcessed = 0, mixed $job = null): ?int
    {
        return match (true) {
            $this->shouldQuit => static::EXIT_SUCCESS,
            $this->memoryExceeded($options->memory) => static::EXIT_MEMORY_LIMIT,
            $this->queueShouldRestart($lastRestart) => static::EXIT_SUCCESS,
            $options->stopWhenEmpty && is_null($job) => static::EXIT_SUCCESS,
            $options->maxTime && hrtime(true) / 1e9 - $startTime >= $options->maxTime => static::EXIT_SUCCESS,
            $options->maxJobs && $jobsProcessed >= $options->maxJobs => static::EXIT_SUCCESS,
            default => null
        };
    }

    /**
     * Process the next job on the queue.
     */
    public function runNextJob(string $connectionName, string $queue, WorkerOptions $options): void
    {
        $job = $this->getNextJob(
            $this->manager->connection($connectionName),
            $queue
        );

        // If we're able to pull a job off of the stack, we will process it and then return
        // from this method. If there is no job on the queue, we will "sleep" the worker
        // for the specified number of seconds, then keep processing jobs after sleep.
        if ($job) {
            $this->runJob($job, $connectionName, $options);
            return;
        }

        $this->sleep($options->sleep);
    }

    /**
     * Get the next job from the queue connection.
     */
    protected function getNextJob(QueueContract $connection, string $queue): ?JobContract
    {
        $popJobCallback = function ($queue, $index = 0) use ($connection) {
            /** @var RedisQueue $connection */
            return $connection->pop($queue, $index);
        };

        $this->raiseBeforeJobPopEvent($connection->getConnectionName());

        try {
            if (isset(static::$popCallbacks[$this->name])) {
                return tap(
                    (static::$popCallbacks[$this->name])($popJobCallback, $queue),
                    fn ($job) => $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job)
                );
            }

            foreach (explode(',', $queue) as $index => $queue) {
                if (! is_null($job = $popJobCallback($queue, $index))) {
                    $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);

                    return $job;
                }
            }
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            $this->stopWorkerIfLostConnection($e);

            $this->sleep(1);
        }

        return null;
    }

    /**
     * Process the given job.
     */
    protected function runJob(JobContract $job, string $connectionName, WorkerOptions $options): void
    {
        try {
            $this->process($connectionName, $job, $options);
        } catch (Throwable $e) {
            $this->exceptions->report($e);

            $this->stopWorkerIfLostConnection($e);
        }
    }

    /**
     * Stop the worker if we have lost connection to a database.
     */
    protected function stopWorkerIfLostConnection(Throwable $e): void
    {
        if ($this->causedByLostConnection($e)) {
            $this->shouldQuit = true;
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

        try {
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
            if (in_array($runningJobId, $this->timeoutJobIds)) {
                return;
            }

            $this->raiseAfterJobEvent($connectionName, $job);
        } catch (Throwable $e) {
            $exceptionOccurred = true;

            $this->handleJobException($connectionName, $job, $options, $e);
        } finally {
            if ($runningJobId) {
                unset($this->runningJobs[$runningJobId]);
            }

            $this->events->dispatch(new JobAttempted(
                $connectionName,
                $job,
                $exceptionOccurred ?? false
            ));
        }
    }

    /**
     * Register a coroutine job to running jobs.
     */
    protected function registerCoroutineJob(JobContract $job, WorkerOptions $options): string
    {
        $this->runningJobs[$jobId = Str::uuid()->toString()] = [
            'job' => $job,
            'start_at' => $startAt = microtime(true),
            'expires_at' => $startAt + $this->timeoutForJob($job, $options),
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
            }

            $this->raiseExceptionOccurredJobEvent(
                $connectionName,
                $job,
                $e
            );
        } finally {
            // If we catch an exception, we will attempt to release the job back onto the queue
            // so it is not lost entirely. This'll let the job be retried at a later time by
            // another listener (or this same one). We will re-throw this exception after.
            if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
                $job->release($this->calculateBackoff($job, $options));

                $this->events->dispatch(new JobReleasedAfterException(
                    $connectionName,
                    $job
                ));
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

        if ($retryUntil && Carbon::now()->getTimestamp() <= $retryUntil) {
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

        if ($job->retryUntil() && $job->retryUntil() <= Carbon::now()->getTimestamp()) {
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

        /* @phpstan-ignore-next-line */
        if (! $this->cache->get('job-exceptions:' . $uuid)) {
            /* @phpstan-ignore-next-line */
            $this->cache->put('job-exceptions:' . $uuid, 0, Carbon::now()->addDay());
        }

        /* @phpstan-ignore-next-line */
        if ($maxExceptions <= $this->cache->increment('job-exceptions:' . $uuid)) {
            /* @phpstan-ignore-next-line */
            $this->cache->forget('job-exceptions:' . $uuid);

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
     * Raise the before job has been popped.
     */
    protected function raiseBeforeJobPopEvent(string $connectionName): void
    {
        $this->events->dispatch(new JobPopping($connectionName));
    }

    /**
     * Raise the after job has been popped.
     */
    protected function raiseAfterJobPopEvent(string $connectionName, ?JobContract $job): void
    {
        $this->events->dispatch(new JobPopped(
            $connectionName,
            $job
        ));
    }

    /**
     * Raise the before queue job event.
     */
    protected function raiseBeforeJobEvent(string $connectionName, ?JobContract $job): void
    {
        $this->events->dispatch(new JobProcessing(
            $connectionName,
            $job
        ));
    }

    /**
     * Raise the after queue job event.
     */
    protected function raiseAfterJobEvent(string $connectionName, JobContract $job): void
    {
        $this->events->dispatch(new JobProcessed(
            $connectionName,
            $job
        ));
    }

    /**
     * Raise the exception occurred queue job event.
     */
    protected function raiseExceptionOccurredJobEvent(string $connectionName, ?JobContract $job, Throwable $e): void
    {
        $this->events->dispatch(new JobExceptionOccurred(
            $connectionName,
            $job,
            $e
        ));
    }

    /**
     * Determine if the queue worker should restart.
     */
    protected function queueShouldRestart(?int $lastRestart): bool
    {
        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    /**
     * Get the last queue restart timestamp, or null.
     */
    protected function getTimestampOfLastQueueRestart(): ?int
    {
        if ($this->cache) {
            /* @phpstan-ignore-next-line */
            return (int) $this->cache->get('illuminate:queue:restart');
        }

        return null;
    }

    /**
     * Enable async signals for the process.
     */
    protected function listenForSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGQUIT, fn () => $this->shouldQuit = true);
        pcntl_signal(SIGTERM, fn () => $this->shouldQuit = true);
        pcntl_signal(SIGUSR2, fn () => $this->paused = true);
        pcntl_signal(SIGCONT, fn () => $this->paused = false);
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
    public function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     */
    public function stop(int $status = 0, ?WorkerOptions $options = null): int
    {
        $this->events->dispatch(new WorkerStopping($status, $options));

        return $status;
    }

    /**
     * Kill the process.
     *
     * @return never
     */
    public function kill(int $status = 0, ?WorkerOptions $options = null): void
    {
        $this->events->dispatch(new WorkerStopping($status, $options));

        // If there are any active coroutines, we will need to wait for
        // them to finish before we can exit.
        while ($this->hasActiveCoroutines()) {
            $this->sleep(1);
        }

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
        usleep((int) ($seconds * 1000000));
    }

    /**
     * Set the cache repository implementation.
     */
    public function setCache(CacheFactory $cache): static
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Set the name of the worker.
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Register a callback to be executed to pick jobs.
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
     */
    public function setManager(QueueManager $manager): void
    {
        $this->manager = $manager;
    }
}
