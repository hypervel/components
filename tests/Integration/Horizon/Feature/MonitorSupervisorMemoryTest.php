<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Events\SupervisorLooped;
use Hypervel\Horizon\Events\SupervisorOutOfMemory;
use Hypervel\Horizon\Listeners\MonitorSupervisorMemory;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;

class MonitorSupervisorMemoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
    }

    public function testSupervisorIsTerminatedWhenUsingTooMuchMemory(): void
    {
        Event::fake([SupervisorOutOfMemory::class]);

        $monitor = new MonitorSupervisorMemory;

        $supervisor = m::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');

        $supervisor->shouldReceive('memoryUsage')->andReturn(192);
        $supervisor->shouldReceive('terminate')->once()->with(12);

        $monitor->handle(new SupervisorLooped($supervisor));

        Event::assertDispatched(
            fn (SupervisorOutOfMemory $event): bool => $event->supervisor === $supervisor
                && $event->getMemoryUsage() === 192.0
        );
    }

    public function testPassiveObserverDoesNotCauseOutOfMemoryEventToDispatch(): void
    {
        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            SupervisorOutOfMemory::class,
            static function (SupervisorOutOfMemory $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $supervisor = m::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');
        $supervisor->shouldReceive('memoryUsage')->once()->andReturn(192);
        $supervisor->shouldReceive('terminate')->once()->with(12);

        (new MonitorSupervisorMemory)->handle(new SupervisorLooped($supervisor));

        $this->assertSame([], $observedEvents);
    }

    public function testSupervisorIsNotTerminatedWhenUsingLowMemory(): void
    {
        $monitor = new MonitorSupervisorMemory;

        $supervisor = m::mock(Supervisor::class);
        $supervisor->options = new SupervisorOptions('redis', 'default');

        $supervisor->shouldReceive('memoryUsage')->andReturn(64);
        $supervisor->shouldReceive('terminate')->never();

        $monitor->handle(new SupervisorLooped($supervisor));
    }
}
