<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Listeners\MonitorMasterSupervisorMemory;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Support\Environment;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class MonitorMasterSupervisorMemoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $environment = Mockery::mock(Environment::class);
        $environment->shouldReceive('isTesting')->andReturn(false);
        $this->app->instance(Environment::class, $environment);
    }

    public function testSupervisorIsTerminatedWhenUsingTooMuchMemory()
    {
        $monitor = new MonitorMasterSupervisorMemory();

        $master = Mockery::mock(MasterSupervisor::class);

        $master->shouldReceive('memoryUsage')->andReturn(192);
        $master->shouldReceive('output')->once()->with('error', 'Memory limit exceeded: Using 192/64MB. Consider increasing horizon.memory_limit.');
        $master->shouldReceive('terminate')->once()->with(12);

        $monitor->handle(new MasterSupervisorLooped($master));
    }

    public function testSupervisorIsNotTerminatedWhenUsingLowMemory()
    {
        $monitor = new MonitorMasterSupervisorMemory();

        $master = Mockery::mock(MasterSupervisor::class);

        $master->shouldReceive('memoryUsage')->andReturn(16);
        $master->shouldReceive('terminate')->never();

        $monitor->handle(new MasterSupervisorLooped($master));
    }
}
