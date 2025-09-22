<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Events\SupervisorLooped;
use Hypervel\Horizon\Listeners\MonitorSupervisorMemory;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Horizon\IntegrationTest;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class MonitorSupervisorMemoryTest extends IntegrationTest
{
    public function testSupervisorIsTerminatedWhenUsingTooMuchMemory()
    {
        $monitor = new MonitorSupervisorMemory();

        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');

        $supervisor->shouldReceive('memoryUsage')->andReturn(192);
        $supervisor->shouldReceive('terminate')->once()->with(12);

        $monitor->handle(new SupervisorLooped($supervisor));
    }

    public function testSupervisorIsNotTerminatedWhenUsingLowMemory()
    {
        $monitor = new MonitorSupervisorMemory();

        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');

        $supervisor->shouldReceive('memoryUsage')->andReturn(64);
        $supervisor->shouldReceive('terminate')->never();

        $monitor->handle(new SupervisorLooped($supervisor));
    }
}
