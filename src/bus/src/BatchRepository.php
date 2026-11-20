<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;

interface BatchRepository
{
    /**
     * Retrieve a list of batches.
     *
     * @return Batch[]
     */
    public function get(int $limit, mixed $before): array;

    /**
     * Retrieve information about an existing batch.
     */
    public function find(string $batchId): ?Batch;

    /**
     * Get the retry lookup IDs of the batch's outstanding failures.
     *
     * @return list<string>
     */
    public function getFailedJobIds(string $batchId): array;

    /**
     * Determine if the job's counting identity has an outstanding failure.
     */
    public function hasFailedJob(string $batchId, string $jobId): bool;

    /**
     * Store a new pending batch.
     */
    public function store(PendingBatch $batch): Batch;

    /**
     * Increment the total number of jobs within the batch.
     */
    public function incrementTotalJobs(string $batchId, int $amount): void;

    /**
     * Record a success once per counting identity, returning null after a prior success or deletion.
     *
     * Repositories reporting first-pass completion also finish the batch in the same update.
     */
    public function decrementPendingJobs(string $batchId, string $jobId): ?UpdatedBatchJobCounts;

    /**
     * Record a failure unless the job already succeeded or the batch was deleted.
     *
     * A chain position may have a different retry lookup ID. Retain the first one until success.
     */
    public function incrementFailedJobs(string $batchId, string $jobId, ?string $retryId = null): ?UpdatedBatchJobCounts;

    /**
     * Mark the batch that has the given ID as finished.
     */
    public function markAsFinished(string $batchId): void;

    /**
     * Cancel the batch that has the given ID.
     */
    public function cancel(string $batchId): void;

    /**
     * Delete the batch that has the given ID.
     */
    public function delete(string $batchId): void;

    /**
     * Execute the given Closure within a storage specific transaction.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Rollback the last database transaction for the connection.
     */
    public function rollBack(): void;
}
