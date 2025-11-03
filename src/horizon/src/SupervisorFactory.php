<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class SupervisorFactory
{
    /**
     * Create a new supervisor instance.
     */
    public function make(SupervisorOptions $options): Supervisor
    {
        return new Supervisor($options);
    }
}
