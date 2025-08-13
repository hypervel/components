<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

class MasterSupervisorDeployed
{
    /**
     * Create a new event instance.
     *
     * @param string $master the master supervisor that was deployed
     */
    public function __construct(
        public string $master
    ) {
    }
}
