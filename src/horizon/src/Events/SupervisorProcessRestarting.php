<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\SupervisorProcess;

class SupervisorProcessRestarting
{
    /**
     * The supervisor process instance.
     */
    public SupervisorProcess $process;

    /**
     * Create a new event instance.
     */
    public function __construct(SupervisorProcess $process)
    {
        $this->process = $process;
    }
}
