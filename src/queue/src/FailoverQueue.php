<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Queue\Events\QueueFailedOver;
use RuntimeException;
use Throwable;

class FailoverQueue extends Queue implements QueueContract
{
    /**
     * The queues which failed on the last action.
     *
     * @var list<string>
     */
    protected array $failingQueues = [];

    /**
     * Create a new failover queue instance.
     */
    public function __construct(
        public QueueManager $manager,
        public Dispatcher $events,
        public array $connections
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->size($queue);
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->pendingSize($queue);
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->delayedSize($queue);
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->reservedSize($queue);
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->manager
            ->connection($this->connections[0])
            ->creationTimeOfOldestPendingJob($queue);
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?JobContract
    {
        return $this->manager->connection($this->connections[0])->pop($queue);
    }

    /**
     * Attempt the given method on all connections.
     *
     * @throws Throwable
     */
    protected function attemptOnAllConnections(string $method, array $arguments, object|string|null $job = null): mixed
    {
        [$lastException, $failedQueues] = [null, []];

        try {
            foreach ($this->connections as $connection) {
                try {
                    return $this->manager->connection($connection)->{$method}(...$arguments);
                } catch (Throwable $e) {
                    $lastException = $e;

                    $failedQueues[] = $connection;

                    if ($job !== null && ! in_array($connection, $this->failingQueues)) {
                        $this->events->dispatch(new QueueFailedOver($connection, $job, $e));
                    }
                }
            }
        } finally {
            $this->failingQueues = $failedQueues;
        }

        throw $lastException ?? new RuntimeException('All failover queue connections failed.');
    }
}
