<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\MasterSupervisor;

class MasterSupervisorOutOfMemory
{
    /**
     * Create a new event instance.
     *
     * @param MasterSupervisor $master the master supervisor instance
     */
    public function __construct(
        public MasterSupervisor $master
    ) {
    }
}
