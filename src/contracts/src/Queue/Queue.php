<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Collection;
use UnitEnum;

interface Queue
{
    /**
     * Get the size of the queue.
     */
    public function size(UnitEnum|string|null $queue = null): int;

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(UnitEnum|string|null $queue = null): int;

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(UnitEnum|string|null $queue = null): int;

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(UnitEnum|string|null $queue = null): int;

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int;

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int;

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int;

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int;

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int;

    /**
     * Get the pending jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function pendingJobs(UnitEnum|string|null $queue = null): Collection;

    /**
     * Get the delayed jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function delayedJobs(UnitEnum|string|null $queue = null): Collection;

    /**
     * Get the reserved jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function reservedJobs(UnitEnum|string|null $queue = null): Collection;

    /**
     * Get all pending jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allPendingJobs(): Collection;

    /**
     * Get all delayed jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allDelayedJobs(): Collection;

    /**
     * Get all reserved jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allReservedJobs(): Collection;

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed;

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(UnitEnum|string|null $queue, object|string $job, mixed $data = ''): mixed;

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed;

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed;

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(UnitEnum|string|null $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed;

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', UnitEnum|string|null $queue = null): mixed;

    /**
     * Pop the next job off of the queue.
     */
    public function pop(UnitEnum|string|null $queue = null): ?Job;

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string;

    /**
     * Set the connection name for the queue.
     *
     * @return $this
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function setConnectionName(string $name): static;
}
