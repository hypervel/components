<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Events\MasterSupervisorOutOfMemory;

class MonitorMasterSupervisorMemory
{
    /**
     * Handle the event.
     */
    public function handle(MasterSupervisorLooped $event): void
    {
        // When we run all tests, the memory usage may exceed the limit. So we skip this check in testing environment.
        if (app()->runningUnitTests()) {
            return;
        }

        $master = $event->master;

        $memoryLimit = config()->integer('horizon.memory_limit');

        if ($master->memoryUsage() > $memoryLimit) {
            /** @var Dispatcher $events */
            $events = app('events');

            if ($events->hasListeners(MasterSupervisorOutOfMemory::class)) {
                $events->dispatch(new MasterSupervisorOutOfMemory($master));
            }

            $master->output('error', 'Memory limit exceeded: Using ' . ceil($master->memoryUsage()) . '/' . $memoryLimit . 'MB. Consider increasing horizon.memory_limit.');

            $master->terminate(12);
        }
    }
}
