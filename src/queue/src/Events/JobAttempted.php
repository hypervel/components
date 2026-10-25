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
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public ?Throwable $exception = null,
        public ?float $duration = null,
    ) {
    }

    /**
     * Determine if the job completed with failing or an unhandled exception occurring.
     */
    public function successful(): bool
    {
        return ! $this->job->hasFailed() && is_null($this->exception);
    }
}
