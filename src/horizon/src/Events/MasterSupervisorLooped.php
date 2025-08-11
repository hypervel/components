<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\MasterSupervisor;

class MasterSupervisorLooped
{
    /**
     * The master supervisor instance.
     */
    public MasterSupervisor $master;

    /**
     * Create a new event instance.
     */
    public function __construct(MasterSupervisor $master)
    {
        $this->master = $master;
    }
}
