<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Exception;
use Hypervel\Queue\Jobs\Job;

class JobFailed extends RedisEvent
{
    /**
     * Create a new event instance.
     *
     * @param Exception $exception the exception that caused the failure
     * @param Job $job the queue job instance
     */
    public function __construct(
        public Exception $exception,
        public Job $job,
        string $payload
    ) {
        parent::__construct($payload);
    }
}
