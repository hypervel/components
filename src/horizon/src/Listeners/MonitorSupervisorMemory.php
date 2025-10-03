<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Events\SupervisorLooped;
use Hypervel\Horizon\Events\SupervisorOutOfMemory;
use Hypervel\Support\Environment;

class MonitorSupervisorMemory
{
    /**
     * Handle the event.
     */
    public function handle(SupervisorLooped $event): void
    {
        // When we run all tests, the memory usage may exceed the limit. So we skip this check in testing environment.
        if (app(Environment::class)->isTesting()) {
            return;
        }

        $supervisor = $event->supervisor;

        if (($memoryUsage = $supervisor->memoryUsage()) > $supervisor->options->memory) {
            event((new SupervisorOutOfMemory($supervisor))->setMemoryUsage($memoryUsage));

            $supervisor->terminate(12);
        }
    }
}
