<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Exception;
use Hypervel\Queue\Jobs\Job;

class JobFailed extends RedisEvent
{
    /**
     * The exception that caused the failure.
     */
    public Exception $exception;

    /**
     * The queue job instance.
     */
    public Job $job;

    /**
     * Create a new event instance.
     */
    public function __construct(Exception $exception, Job $job, string $payload)
    {
        $this->job = $job;
        $this->exception = $exception;

        parent::__construct($payload);
    }
}
