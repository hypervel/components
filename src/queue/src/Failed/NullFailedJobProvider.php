<?php

declare(strict_types=1);

namespace Hypervel\Queue\Failed;

use Throwable;

class NullFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface
{
    /**
     * Log a failed job into storage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): null|int|string
    {
        return null;
    }

    /**
     * Get the IDs of all of the failed jobs.
     */
    public function ids(?string $queue = null): array
    {
        return [];
    }

    /**
     * Get a list of all of the failed jobs.
     */
    public function all(): array
    {
        return [];
    }

    /**
     * Get a single failed job.
     */
    public function find(mixed $id): ?object
    {
        return null;
    }

    /**
     * Delete a single failed job from storage.
     */
    public function forget(mixed $id): bool
    {
        return true;
    }

    /**
     * Flush all of the failed jobs from storage.
     */
    public function flush(?int $hours = null): void
    {
    }

    /**
     * Count the failed jobs.
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        return 0;
    }
}
