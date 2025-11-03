<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

class MasterSupervisorReviving
{
    /**
     * Create a new event instance.
     *
     * @param string $master the master supervisor that was dead
     */
    public function __construct(
        public string $master
    ) {
    }
}
