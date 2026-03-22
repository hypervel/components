<?php

declare(strict_types=1);

namespace Hypervel\Bus;

class UpdatedBatchJobCounts
{
    /**
     * Create a new batch job counts object.
     */
    public function __construct(
        public int $pendingJobs = 0,
        public int $failedJobs = 0,
    ) {
    }

    /**
     * Determine if all jobs have run exactly once.
     */
    public function allJobsHaveRanExactlyOnce(): bool
    {
        return ($this->pendingJobs - $this->failedJobs) === 0;
    }
}
