<?php

declare(strict_types=1);

namespace Hypervel\Queue\Contracts;

use DateInterval;
use DateTimeInterface;

interface Queue
{
    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int;

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed;

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed;

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed;

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed;

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed;

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed;

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job;

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string;

    /**
     * Set the connection name for the queue.
     */
    public function setConnectionName(string $name): static;
}
