<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use UnitEnum;

/**
 * A connection selected on a queue fake, which records jobs on the fake and sends the jobs it does not fake to that connection.
 *
 * @mixin QueueFake
 */
class QueueConnectionFake implements Queue
{
    /**
     * Create a new fake queue connection instance.
     */
    public function __construct(
        protected QueueFake $fake,
        protected string $connection,
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(UnitEnum|string|null $queue = null): int
    {
        return $this->fake->size($queue);
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(UnitEnum|string|null $queue = null): int
    {
        return $this->fake->pendingSize($queue);
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->fake->delayedSize($queue);
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->fake->reservedSize($queue);
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int
    {
        return $this->fake->creationTimeOfOldestPendingJob($queue);
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return $this->fake->pushToConnection($this->connection, $job, $data, $queue);
    }

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(UnitEnum|string|null $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        return $this->fake->pushRaw($payload, $queue, $options);
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
        UnitEnum|string|null $queue = null,
    ): mixed {
        return $this->fake->pushToConnection($this->connection, $job, $data, $queue, $delay);
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(
        UnitEnum|string|null $queue,
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
    ): mixed {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return $this->fake->bulkToConnection($this->connection, $jobs, $data, $queue);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(UnitEnum|string|null $queue = null): ?Job
    {
        return $this->fake->pop($queue);
    }

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string
    {
        return $this->fake->getConnectionName();
    }

    /**
     * Set the connection name for the queue.
     *
     * @return $this
     */
    public function setConnectionName(string $name): static
    {
        return $this;
    }

    /**
     * Forward other calls to the queue fake.
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->fake->{$method}(...$parameters);

        return $result === $this->fake ? $this : $result;
    }
}
