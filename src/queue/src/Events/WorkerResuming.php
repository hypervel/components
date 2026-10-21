<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\WorkerOptions;

class WorkerResuming
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?string $connectionName = null,
        public ?string $queue = null,
        public ?WorkerOptions $workerOptions = null,
    ) {
    }
}
