<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Events\SupervisorLooped;
use Hypervel\Horizon\Listeners\MonitorSupervisorMemory;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Support\Environment;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class MonitorSupervisorMemoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $environment = m::mock(Environment::class);
        $environment->shouldReceive('isTesting')->andReturn(false);
        $this->app->instance(Environment::class, $environment);
    }

    public function testSupervisorIsTerminatedWhenUsingTooMuchMemory()
    {
        $monitor = new MonitorSupervisorMemory();

        $supervisor = m::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');

        $supervisor->shouldReceive('memoryUsage')->andReturn(192);
        $supervisor->shouldReceive('terminate')->once()->with(12);

        $monitor->handle(new SupervisorLooped($supervisor));
    }

    public function testSupervisorIsNotTerminatedWhenUsingLowMemory()
    {
        $monitor = new MonitorSupervisorMemory();

        $supervisor = m::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');

        $supervisor->shouldReceive('memoryUsage')->andReturn(64);
        $supervisor->shouldReceive('terminate')->never();

        $monitor->handle(new SupervisorLooped($supervisor));
    }
}
