<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Support\Collection;
use Throwable;
use UnitEnum;

class SyncQueue extends Queue implements QueueContract
{
    /**
     * The name of the default queue.
     */
    protected string $default = 'sync';

    /**
     * Create a new sync queue instance.
     */
    public function __construct(
        protected bool $dispatchAfterCommit = false
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int
    {
        return 0;
    }

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int
    {
        return 0;
    }

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int
    {
        return 0;
    }

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int
    {
        return 0;
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(UnitEnum|string|null $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return new Collection;
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int
    {
        return null;
    }

    /**
     * Push a new job onto the queue.
     *
     * @throws Throwable
     */
    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        $queue = $this->normalizeQueue($queue);

        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has('db.transactions')
        ) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');

            // Unlike Laravel's late capture, preserve dispatch-time data and context
            // before the caller changes them, as the other queue drivers do.
            $payload = $this->createPayload($job, $queue, $data);

            $this->deferEnqueueAfterCommit(
                $transactions,
                $job,
                static function (Queue $owner) use ($job, $payload, $queue): int {
                    /** @var SyncQueue $owner */
                    $result = $owner->executePayload($payload, $queue);
                    $owner->acceptDispatchLocks($job);

                    return $result;
                },
            );

            return null;
        }

        return $this->executeJob($job, $data, $queue);
    }

    /**
     * Execute a given job synchronously.
     *
     * @throws Throwable
     */
    protected function executeJob(object|string $job, mixed $data = '', ?string $queue = null): int
    {
        $result = $this->executePayload($this->createPayload($job, $queue, $data), $queue);

        $this->acceptDispatchLocks($job);

        return $result;
    }

    /**
     * Execute a serialized job payload synchronously.
     *
     * @throws Throwable
     */
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        // JobProcessing hydrates the payload's context. Keep it through all job
        // events without replacing the context of the dispatching coroutine.
        return $this->withLogContext(null, function () use ($payload, $queue): int {
            if (CoroutineContext::get(self::OUTBOX_FORWARDED_CONTEXT_KEY) === null) {
                return $this->processPayload($payload, $queue);
            }

            // A running handler may dispatch independent work through the same string handler.
            return $this->withOutboxForwarding(null, fn (): int => $this->processPayload($payload, $queue));
        });
    }

    /**
     * Process a serialized job payload and raise its lifecycle events.
     *
     * @throws Throwable
     */
    protected function processPayload(string $payload, ?string $queue = null): int
    {
        $queueJob = $this->resolveJob($payload, $queue);
        $canceled = false;

        try {
            $this->raiseBeforeJobEvent($queueJob);

            $startedAt = hrtime(true);

            try {
                $queueJob->fire();
            } finally {
                $duration = round((hrtime(true) - $startedAt) / 1e6, 2);
            }

            $this->raiseAfterJobEvent($queueJob, $duration);
        } catch (Throwable $e) {
            if (Coroutine::isCancellation($e)) {
                $canceled = true;

                throw $e;
            }

            $exceptionOccurred = $e;

            try {
                $this->handleException($queueJob, $e);
            } catch (Throwable $exception) {
                $canceled = Coroutine::isCancellation($exception);

                throw $exception;
            }
        } finally {
            if (! $canceled) {
                $this->raiseJobAttemptedEvent($queueJob, $exceptionOccurred ?? null, $duration ?? null);
            }
        }

        return 0;
    }

    /**
     * Resolve a Sync job instance.
     */
    protected function resolveJob(string $payload, ?string $queue): SyncJob
    {
        $queue = $queue === null || $queue === '' ? $this->default : $queue;

        return new SyncJob($this->container, $payload, $this->connectionName, $queue);
    }

    /**
     * Raise the before queue job event.
     */
    protected function raiseBeforeJobEvent(JobContract $job): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(JobProcessing::class)) {
                $events->dispatch(new JobProcessing($this->connectionName, $job));
            }
        }
    }

    /**
     * Raise the after queue job event.
     */
    protected function raiseAfterJobEvent(JobContract $job, ?float $duration = null): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(JobProcessed::class)) {
                $events->dispatch(new JobProcessed($this->connectionName, $job, $duration));
            }
        }
    }

    /**
     * Raise the job attempted event.
     */
    protected function raiseJobAttemptedEvent(JobContract $job, ?Throwable $exceptionOccurred = null, ?float $duration = null): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(JobAttempted::class)) {
                $events->dispatch(new JobAttempted($this->connectionName, $job, $exceptionOccurred, $duration));
            }
        }
    }

    /**
     * Raise the exception occurred queue job event.
     */
    protected function raiseExceptionOccurredJobEvent(JobContract $job, Throwable $e): void
    {
        if ($this->container->bound('events')) {
            /** @var EventDispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(JobExceptionOccurred::class)) {
                $events->dispatch(new JobExceptionOccurred($this->connectionName, $job, $e));
            }
        }
    }

    /**
     * Handle an exception that occurred while processing a job.
     *
     * @throws Throwable
     */
    protected function handleException(JobContract $queueJob, Throwable $e): void
    {
        $this->raiseExceptionOccurredJobEvent($queueJob, $e);

        $queueJob->fail($e);

        throw $e;
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        return $this->executePayload($payload, $this->normalizeQueue($queue));
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Keep local queue execution out of durable outbox storage.
     */
    protected function shouldStageViaOutbox(object|string $job): bool
    {
        return false;
    }

    /**
     * Degrade selected outbox delivery to local execution after commit.
     */
    protected function shouldDispatchAfterCommit(object|string $job): bool
    {
        if (parent::shouldDispatchAfterCommit($job)) {
            return true;
        }

        if (! $this->shouldDispatchViaOutbox($job) || ! $this->container->has('db.transactions')) {
            return false;
        }

        /** @var DatabaseTransactionsManager $transactions */
        $transactions = $this->container->make('db.transactions');

        return $transactions->hasApplicableTransaction();
    }

    /**
     * Honor the selected transaction without resolving outbox storage services.
     */
    protected function deferEnqueueAfterCommit(DatabaseTransactionsManager $transactions, object|string $job, Closure $enqueue): void
    {
        $this->deferBatchEnqueueAfterCommit(
            $transactions,
            [$job],
            $enqueue,
            $this->resolveOutboxCommitConnection($transactions, $job),
        );
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(UnitEnum|string|null $queue = null): ?JobContract
    {
        return null;
    }
}
