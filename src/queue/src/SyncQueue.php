<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Database\TransactionManager;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Queue\Contracts\Queue as QueueContract;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Jobs\SyncJob;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

class SyncQueue extends Queue implements QueueContract
{
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
    public function size(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Push a new job onto the queue.
     *
     * @throws Throwable
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has(TransactionManager::class)
        ) {
            return $this->container->get(TransactionManager::class)
                ->addCallback(
                    fn () => $this->executeJob($job, $data, $queue)
                );
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
        $queueJob = $this->resolveJob($this->createPayload($job, $queue, $data), $queue);

        try {
            $this->raiseBeforeJobEvent($queueJob);

            $queueJob->fire();

            $this->raiseAfterJobEvent($queueJob);
        } catch (Throwable $e) {
            $this->handleException($queueJob, $e);
        }

        return 0;
    }

    /**
     * Resolve a Sync job instance.
     */
    protected function resolveJob(string $payload, ?string $queue): SyncJob
    {
        return new SyncJob($this->container, $payload, $this->connectionName, $queue);
    }

    /**
     * Raise the before queue job event.
     */
    protected function raiseBeforeJobEvent(JobContract $job): void
    {
        if ($this->container->has(EventDispatcherInterface::class)) {
            $this->container->get(EventDispatcherInterface::class)
                ->dispatch(new JobProcessing($this->connectionName, $job));
        }
    }

    /**
     * Raise the after queue job event.
     */
    protected function raiseAfterJobEvent(JobContract $job): void
    {
        if ($this->container->has(EventDispatcherInterface::class)) {
            $this->container->get(EventDispatcherInterface::class)
                ->dispatch(new JobProcessed($this->connectionName, $job));
        }
    }

    /**
     * Raise the exception occurred queue job event.
     */
    protected function raiseExceptionOccurredJobEvent(JobContract $job, Throwable $e): void
    {
        if ($this->container->has(EventDispatcherInterface::class)) {
            $this->container->get(EventDispatcherInterface::class)
                ->dispatch(new JobExceptionOccurred($this->connectionName, $job, $e));
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
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?JobContract
    {
        return null;
    }
}
