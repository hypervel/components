<?php

declare(strict_types=1);

namespace Hypervel\Bus;

class UpdatedBatchJobCounts
{
    /**
     * Create a new batch job counts object.
     *
     * @param null|bool $completedFirstPass Whether the update ran the batch's last job that had not yet run, or null when the repository does not report it. A repository that reports it also marks a batch that allows failures as finished in that same update.
     */
    public function __construct(
        public int $pendingJobs = 0,
        public int $failedJobs = 0,
        public ?bool $completedFirstPass = null,
    ) {
    }

    /**
     * Determine if all jobs have run exactly once.
     */
    public function allJobsHaveRanExactlyOnce(): bool
    {
        return $this->completedFirstPass ?? ($this->pendingJobs - $this->failedJobs) === 0;
    }
}
