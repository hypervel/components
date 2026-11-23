<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Support\CarbonImmutable;

class UpdatedBatchJobCounts
{
    /**
     * Create a new batch job counts object.
     *
     * @param null|bool $completedFirstPass Whether the last previously unreported job ran. Repositories that report this flag finish the batch atomically at zero pending, or when only allowed failures remain. Null leaves finishing to the batch.
     * @param null|int $totalJobs the total recorded with this transition, used to detect a later addition
     */
    public function __construct(
        public int $pendingJobs = 0,
        public int $failedJobs = 0,
        public ?bool $completedFirstPass = null,
        public ?int $totalJobs = null,
        public ?int $outstandingFailedJobs = null,
        public ?CarbonImmutable $finishedAt = null,
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
