<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\WorkerProcess;

class UnableToLaunchProcess
{
    /**
     * Create a new event instance.
     *
     * @param WorkerProcess $process the worker process instance
     */
    public function __construct(
        public WorkerProcess $process
    ) {
    }
}
