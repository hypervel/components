<?php

declare(strict_types=1);

namespace Hypervel\Queue\Failed;

use Throwable;

/**
 * @method array ids(string $queue = null)
 */
interface FailedJobProviderInterface
{
    /**
     * Log a failed job into storage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): null|int|string;

    /**
     * Get a list of all of the failed jobs.
     */
    public function all(): array;

    /**
     * Get a single failed job.
     */
    public function find(mixed $id): ?object;

    /**
     * Delete a single failed job from storage.
     */
    public function forget(mixed $id): bool;

    /**
     * Flush all of the failed jobs from storage.
     */
    public function flush(?int $hours = null): void;
}
