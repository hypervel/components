<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Listeners;

use Hypervel\Horizon\Events\SupervisorLooped;

class PruneTerminatingProcesses
{
    /**
     * Handle the event.
     */
    public function handle(SupervisorLooped $event): void
    {
        $event->supervisor->pruneTerminatingProcesses();
    }
}
