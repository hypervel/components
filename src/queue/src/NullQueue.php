<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Queue\Contracts\Job;
use Hypervel\Queue\Contracts\Queue as QueueContract;

class NullQueue extends Queue implements QueueContract
{
    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
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
        return null;
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        return null;
    }
}
