<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Bus;

use Closure;
use Hypervel\Bus\Batch;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\UpdatedBatchJobCounts;

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
    public function find(int|string $batchId): ?Batch;

    /**
     * Store a new pending batch.
     */
    public function store(PendingBatch $batch): ?Batch;

    /**
     * Increment the total number of jobs within the batch.
     */
    public function incrementTotalJobs(int|string $batchId, int $amount): void;

    /**
     * Decrement the total number of pending jobs for the batch.
     */
    public function decrementPendingJobs(int|string $batchId, string $jobId): UpdatedBatchJobCounts;

    /**
     * Increment the total number of failed jobs for the batch.
     */
    public function incrementFailedJobs(int|string $batchId, string $jobId): UpdatedBatchJobCounts;

    /**
     * Mark the batch that has the given ID as finished.
     */
    public function markAsFinished(int|string $batchId): void;

    /**
     * Cancel the batch that has the given ID.
     */
    public function cancel(int|string $batchId): void;

    /**
     * Delete the batch that has the given ID.
     */
    public function delete(int|string $batchId): void;

    /**
     * Execute the given Closure within a storage specific transaction.
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Rollback the last database transaction for the connection.
     */
    public function rollBack(): void;
}
