<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures;

use Hypervel\Horizon\Supervisor;

class SupervisorWithFakeMonitor extends Supervisor
{
    public bool $monitoring = false;

    public int $monitorStatus = 0;

    /**
     * Record monitoring and return the configured status.
     */
    public function monitor(): int
    {
        $this->monitoring = true;

        return $this->monitorStatus;
    }
}
