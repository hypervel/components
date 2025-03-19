<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Queue\Contracts\Job;
use Hypervel\Queue\Contracts\Queue;

class QueuePoolProxy extends PoolProxy implements Queue
{
    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }

    /**
     * Set the connection name for the queue.
     */
    public function setConnectionName(string $name): static
    {
        return $this->__call(__FUNCTION__, func_get_args());
    }
}
