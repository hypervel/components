<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Events\MasterSupervisorOutOfMemory;
use Hypervel\Horizon\Listeners\MonitorMasterSupervisorMemory;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;

class MonitorMasterSupervisorMemoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
    }

    public function testSupervisorIsTerminatedWhenUsingTooMuchMemory(): void
    {
        Event::fake([MasterSupervisorOutOfMemory::class]);

        $monitor = new MonitorMasterSupervisorMemory;

        $master = m::mock(MasterSupervisor::class);

        $master->shouldReceive('memoryUsage')->andReturn(192);
        $master->shouldReceive('output')->once()->with('error', 'Memory limit exceeded: Using 192/64MB. Consider increasing horizon.memory_limit.');
        $master->shouldReceive('terminate')->once()->with(12);

        $monitor->handle(new MasterSupervisorLooped($master));

        Event::assertDispatched(
            fn (MasterSupervisorOutOfMemory $event): bool => $event->master === $master
        );
    }

    public function testPassiveObserverDoesNotCauseOutOfMemoryEventToDispatch(): void
    {
        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            MasterSupervisorOutOfMemory::class,
            static function (MasterSupervisorOutOfMemory $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $master = m::mock(MasterSupervisor::class);
        $master->shouldReceive('memoryUsage')->twice()->andReturn(192);
        $master->shouldReceive('output')->once()->with('error', 'Memory limit exceeded: Using 192/64MB. Consider increasing horizon.memory_limit.');
        $master->shouldReceive('terminate')->once()->with(12);

        (new MonitorMasterSupervisorMemory)->handle(new MasterSupervisorLooped($master));

        $this->assertSame([], $observedEvents);
    }

    public function testSupervisorIsNotTerminatedWhenUsingLowMemory(): void
    {
        $monitor = new MonitorMasterSupervisorMemory;

        $master = m::mock(MasterSupervisor::class);

        $master->shouldReceive('memoryUsage')->andReturn(16);
        $master->shouldReceive('terminate')->never();

        $monitor->handle(new MasterSupervisorLooped($master));
    }
}
