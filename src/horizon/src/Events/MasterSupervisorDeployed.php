<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

class MasterSupervisorDeployed
{
    /**
     * The master supervisor that was deployed.
     */
    public string $master;

    /**
     * Create a new event instance.
     */
    public function __construct(string $master)
    {
        $this->master = $master;
    }
}
