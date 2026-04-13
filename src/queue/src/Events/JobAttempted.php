<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Contracts\Queue\Job;
use Throwable;

class JobAttempted
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public ?Throwable $exception = null,
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
