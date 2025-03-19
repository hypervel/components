<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\Contracts\Job;

class JobAttempted
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public bool $exceptionOccurred = false
    ) {
    }

    /**
     * Determine if the job completed with failing or an unhandled exception occurring.
     */
    public function successful(): bool
    {
        return ! $this->job->hasFailed() && ! $this->exceptionOccurred;
    }
}
