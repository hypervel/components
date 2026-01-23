<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Carbon\CarbonInterface;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Bus\Batch;
use Hypervel\Bus\UpdatedBatchJobCounts;
use Hypervel\Support\Carbon;
use Throwable;

class BatchFake extends Batch
{
    /**
     * The jobs that have been added to the batch.
     */
    public array $added = [];

    /**
     * Indicates if the batch has been deleted.
     */
    public bool $deleted = false;

    /**
     * Create a new batch instance.
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $totalJobs,
        public int $pendingJobs,
        public int $failedJobs,
        public array $failedJobIds,
        public array $options,
        public CarbonInterface $createdAt,
        public ?CarbonInterface $cancelledAt = null,
        public ?CarbonInterface $finishedAt = null
    ) {
    }

    /**
     * Get a fresh instance of the batch represented by this ID.
     */
    public function fresh(): static
    {
        return $this;
    }

    /**
     * Add additional jobs to the batch.
     *
     * @param array|Enumerable|object $jobs
     */
    public function add(array|object $jobs): static
    {
        $jobs = Collection::wrap($jobs);

        foreach ($jobs as $job) {
            $this->added[] = $job;
        }

        $this->totalJobs += $jobs->count();

        return $this;
    }

    /**
     * Record that a job within the batch finished successfully, executing any callbacks if necessary.
     */
    public function recordSuccessfulJob(string $jobId): void
    {
    }

    /**
     * Decrement the pending jobs for the batch.
     */
    public function decrementPendingJobs(string $jobId): UpdatedBatchJobCounts
    {
        return new UpdatedBatchJobCounts();
    }

    /**
     * Record that a job within the batch failed to finish successfully, executing any callbacks if necessary.
     */
    public function recordFailedJob(string $jobId, Throwable $e): void
    {
    }

    /**
     * Increment the failed jobs for the batch.
     */
    public function incrementFailedJobs(string $jobId): UpdatedBatchJobCounts
    {
        return new UpdatedBatchJobCounts();
    }

    /**
     * Cancel the batch.
     */
    public function cancel(): void
    {
        $this->cancelledAt = Carbon::now();
    }

    /**
     * Delete the batch from storage.
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * Determine if the batch has been deleted.
     */
    public function deleted(): bool
    {
        return $this->deleted;
    }
}
