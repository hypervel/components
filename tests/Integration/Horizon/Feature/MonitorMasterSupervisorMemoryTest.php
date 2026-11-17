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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class MonitorMasterSupervisorMemoryTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
    }

    public function testSupervisorIsTerminatedWhenUsingTooMuchMemory(): void
    {
        Event::fake([MasterSupervisorOutOfMemory::class]);

        $monitor = new MonitorMasterSupervisorMemory;

        $master = Double::for(MasterSupervisor::class)->strict();

        $master->allows('memoryUsage')->returns(192);
        $master->expects('output')->with('error', 'Memory limit exceeded: Using 192/64MB. Consider increasing horizon.memory_limit.');
        $master->expects('terminate')->with(12);

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

        $master = Double::for(MasterSupervisor::class)->strict();
        $master->expects('memoryUsage')->times(2)->returns(192);
        $master->expects('output')->with('error', 'Memory limit exceeded: Using 192/64MB. Consider increasing horizon.memory_limit.');
        $master->expects('terminate')->with(12);

        (new MonitorMasterSupervisorMemory)->handle(new MasterSupervisorLooped($master));

        $this->assertSame([], $observedEvents);
    }

    public function testSupervisorIsNotTerminatedWhenUsingLowMemory(): void
    {
        $monitor = new MonitorMasterSupervisorMemory;

        $master = Double::for(MasterSupervisor::class)->strict();

        $master->allows('memoryUsage')->returns(16);
        $master->expects('terminate')->never();

        $monitor->handle(new MasterSupervisorLooped($master));
    }
}
