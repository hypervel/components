<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures;

use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorFactory;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Integration\Horizon\Feature\Fakes\SupervisorWithFakeMonitor;

class FakeSupervisorFactory extends SupervisorFactory
{
    public $supervisor;

    public function make(SupervisorOptions $options): Supervisor
    {
        return $this->supervisor = new SupervisorWithFakeMonitor($options);
    }
}
