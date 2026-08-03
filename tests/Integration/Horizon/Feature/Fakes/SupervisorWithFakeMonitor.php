<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fakes;

use Hypervel\Horizon\Supervisor;

class SupervisorWithFakeMonitor extends Supervisor
{
    public bool $monitoring = false;

    public int $monitorStatus = 0;

    public function monitor(): int
    {
        $this->monitoring = true;

        return $this->monitorStatus;
    }
}
