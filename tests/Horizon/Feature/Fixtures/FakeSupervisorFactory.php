<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorFactory;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Horizon\Feature\Fakes\SupervisorWithFakeMonitor;

class FakeSupervisorFactory extends SupervisorFactory
{
    public $supervisor;

    public function make(SupervisorOptions $options): Supervisor
    {
        return $this->supervisor = new SupervisorWithFakeMonitor($options);
    }
}
