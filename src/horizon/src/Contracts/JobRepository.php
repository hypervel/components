<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

use Exception;
use Hypervel\Horizon\JobPayload;
use Hypervel\Support\Collection;
use stdClass;

interface JobRepository
{
    /**
     * Get the next job ID that should be assigned.
     */
    public function nextJobId(): string;

    /**
     * Get the total count of recent jobs.
     */
    public function totalRecent(): int;

    /**
     * Get the total count of failed jobs.
     */
    public function totalFailed(): int;

    /**
     * Get a chunk of recent jobs.
     */
    public function getRecent(?int $afterIndex = null): Collection;

    /**
     * Get a chunk of failed jobs.
     */
    public function getFailed(?int $afterIndex = null): Collection;

    /**
     * Get a chunk of pending jobs.
     */
    public function getPending(?int $afterIndex = null): Collection;

    /**
     * Get a chunk of completed jobs.
     */
    public function getCompleted(?int $afterIndex = null): Collection;

    /**
     * Get a chunk of silenced jobs.
     */
    public function getSilenced(?int $afterIndex = null): Collection;

    /**
     * Get the count of recent jobs.
     */
    public function countRecent(): int;

    /**
     * Get the count of failed jobs.
     */
    public function countFailed(): int;

    /**
     * Get the count of pending jobs.
     */
    public function countPending(): int;

    /**
     * Get the count of completed jobs.
     */
    public function countCompleted(): int;

    /**
     * Get the count of silenced jobs.
     */
    public function countSilenced(): int;

    /**
     * Get the count of the recently failed jobs.
     */
    public function countRecentlyFailed(): int;

    /**
     * Retrieve the jobs with the given IDs.
     */
    public function getJobs(array $ids, mixed $indexFrom = 0): Collection;

    /**
     * Insert the job into storage.
     */
    public function pushed(string $connection, string $queue, JobPayload $payload): void;

    /**
     * Mark the job as reserved.
     */
    public function reserved(string $connection, string $queue, JobPayload $payload): void;

    /**
     * Mark the job as released / pending.
     */
    public function released(string $connection, string $queue, JobPayload $payload): void;

    /**
     * Mark the job as completed and monitored.
     */
    public function remember(string $connection, string $queue, JobPayload $payload): void;

    /**
     * Mark the given jobs as released / pending.
     */
    public function migrated(string $connection, string $queue, Collection $payloads): void;

    /**
     * Handle the storage of a completed job.
     */
    public function completed(JobPayload $payload, bool $failed = false, bool $silenced = false): void;

    /**
     * Delete the given monitored jobs by IDs.
     */
    public function deleteMonitored(array $ids): void;

    /**
     * Trim the recent job list.
     */
    public function trimRecentJobs(): void;

    /**
     * Trim the failed job list.
     */
    public function trimFailedJobs(): void;

    /**
     * Trim the monitored job list.
     */
    public function trimMonitoredJobs(): void;

    /**
     * Find a failed job by ID.
     */
    public function findFailed(string $id): ?stdClass;

    /**
     * Mark the job as failed.
     */
    public function failed(Exception $exception, string $connection, string $queue, JobPayload $payload): void;

    /**
     * Store the retry job ID on the original job record.
     */
    public function storeRetryReference(string $id, string $retryId): void;

    /**
     * Delete a failed job by ID.
     */
    public function deleteFailed(string $id): int;
}
