<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Events;

use Hypervel\Horizon\Supervisor;

class SupervisorLooped
{
    /**
     * Create a new event instance.
     *
     * @param Supervisor $supervisor the supervisor instance
     */
    public function __construct(
        public Supervisor $supervisor
    ) {
    }
}
