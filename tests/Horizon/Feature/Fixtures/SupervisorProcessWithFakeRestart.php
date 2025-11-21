<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

use Hypervel\Horizon\SupervisorProcess;

class SupervisorProcessWithFakeRestart extends SupervisorProcess
{
    public $wasRestarted = false;

    public function restart(): void
    {
        $this->wasRestarted = true;
    }
}
