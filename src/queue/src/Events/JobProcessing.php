<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Contracts\Queue\Job;

class JobProcessing
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $connectionName,
        public Job $job
    ) {
    }
}
