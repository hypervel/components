<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Contracts\Queue\Job;
use Throwable;

class JobFailed
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public Throwable $exception
    ) {
    }
}
