<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Events\MasterSupervisorOutOfMemory;

class MonitorMasterSupervisorMemory
{
    /**
     * Handle the event.
     */
    public function handle(MasterSupervisorLooped $event): void
    {
        $master = $event->master;

        $memoryLimit = config('horizon.memory_limit', 64);

        if ($master->memoryUsage() > $memoryLimit) {
            event(new MasterSupervisorOutOfMemory($master));

            $master->output('error', 'Memory limit exceeded: Using ' . ceil($master->memoryUsage()) . '/' . $memoryLimit . 'MB. Consider increasing horizon.memory_limit.');

            $master->terminate(12);
        }
    }
}
