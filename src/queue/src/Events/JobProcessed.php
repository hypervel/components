<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\Contracts\Job;

class JobProcessed
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
