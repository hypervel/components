<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Contracts\Queue\Job;
use Throwable;

class JobAttempted
{
    /**
     * Create a new event instance.
     *
     * @param null|Throwable $exception the exception, if one occurred while processing the job
     * @param null|float $duration the number of milliseconds the job took to process
     * @param null|float|int $memoryUsage the worker process's peak memory usage in megabytes so far
     * @param bool $canceled whether cancellation stopped the attempt, or its timeout monitor took it over, before its normal completion
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public ?Throwable $exception = null,
        public ?float $duration = null,
        public float|int|null $memoryUsage = null,
        public bool $canceled = false,
    ) {
    }

    /**
     * Determine if the job completed with failing or an unhandled exception occurring.
     */
    public function successful(): bool
    {
        return ! $this->job->hasFailed() && is_null($this->exception) && ! $this->canceled;
    }
}
