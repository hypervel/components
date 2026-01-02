<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Queue\Jobs\Job;
use Throwable;

class JobFailed extends RedisEvent
{
    /**
     * Create a new event instance.
     *
     * @param Throwable $exception the exception that caused the failure
     * @param Job $job the queue job instance
     */
    public function __construct(
        public Throwable $exception,
        public Job $job,
        string $payload
    ) {
        parent::__construct($payload);
    }
}
