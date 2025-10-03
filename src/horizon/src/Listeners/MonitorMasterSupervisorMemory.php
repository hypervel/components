<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Events\MasterSupervisorOutOfMemory;
use Hypervel\Support\Environment;

class MonitorMasterSupervisorMemory
{
    /**
     * Handle the event.
     */
    public function handle(MasterSupervisorLooped $event): void
    {
        // When we run all tests, the memory usage may exceed the limit. So we skip this check in testing environment.
        if (app(Environment::class)->isTesting()) {
            return;
        }

        $master = $event->master;

        $memoryLimit = config('horizon.memory_limit', 64);

        if ($master->memoryUsage() > $memoryLimit) {
            event(new MasterSupervisorOutOfMemory($master));

            $master->output('error', 'Memory limit exceeded: Using ' . ceil($master->memoryUsage()) . '/' . $memoryLimit . 'MB. Consider increasing horizon.memory_limit.');

            $master->terminate(12);
        }
    }
}
