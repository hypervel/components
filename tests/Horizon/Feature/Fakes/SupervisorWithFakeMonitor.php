<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fakes;

use Hypervel\Horizon\Supervisor;

class SupervisorWithFakeMonitor extends Supervisor
{
    public $monitoring = false;

    public function monitor(): void
    {
        $this->monitoring = true;
    }
}
