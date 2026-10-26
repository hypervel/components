<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Contracts\Queue\Job;

class JobProcessed
{
    /**
     * Create a new event instance.
     *
     * @param null|float $duration the number of milliseconds the job took to process
     */
    public function __construct(
        public string $connectionName,
        public Job $job,
        public ?float $duration = null,
    ) {
    }
}
