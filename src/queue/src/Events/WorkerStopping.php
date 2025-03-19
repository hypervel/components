<?php

declare(strict_types=1);

namespace Hypervel\Queue\Events;

use Hypervel\Queue\WorkerOptions;

class WorkerStopping
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public int $status = 0,
        public ?WorkerOptions $workerOptions = null
    ) {
    }
}
