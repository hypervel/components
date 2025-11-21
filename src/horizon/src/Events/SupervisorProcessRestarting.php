<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\SupervisorProcess;

class SupervisorProcessRestarting
{
    /**
     * Create a new event instance.
     *
     * @param SupervisorProcess $process the supervisor process instance
     */
    public function __construct(
        public SupervisorProcess $process
    ) {
    }
}
