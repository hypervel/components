<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\WorkerProcess;

class WorkerProcessRestarting
{
    /**
     * The worker process instance.
     */
    public WorkerProcess $process;

    /**
     * Create a new event instance.
     */
    public function __construct(WorkerProcess $process)
    {
        $this->process = $process;
    }
}
