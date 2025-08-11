<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\Supervisor;

class SupervisorLooped
{
    /**
     * The supervisor instance.
     */
    public Supervisor $supervisor;

    /**
     * Create a new event instance.
     */
    public function __construct(Supervisor $supervisor)
    {
        $this->supervisor = $supervisor;
    }
}
