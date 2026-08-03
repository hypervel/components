<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Events\SupervisorLooped;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorCommands\ContinueWorking;
use Hypervel\Horizon\SupervisorCommands\Scale;
use Hypervel\Horizon\SupervisorCommands\Terminate;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class ProcessExitStatusTest extends IntegrationTestCase
{
    #[RunInSeparateProcess]
    public function testSupervisorTerminationReturnsItsStatusAndSkipsLaterCommands(): void
    {
        Event::fake([SupervisorLooped::class]);
        $supervisor = new Supervisor(new SupervisorOptions('terminating-supervisor', 'redis'));
        $commands = app(HorizonCommandQueue::class);
        $commands->push($supervisor->name, Terminate::class, ['status' => 12]);
        $commands->push($supervisor->name, ContinueWorking::class);
        $commands->push($supervisor->name, Scale::class, ['scale' => 2]);

        $this->assertSame(12, $supervisor->monitor());
        $this->assertFalse($supervisor->working);
        $this->assertSame(0, $supervisor->totalProcessCount());
        $this->assertNull(app(SupervisorRepository::class)->find($supervisor->name));
        Event::assertNotDispatched(SupervisorLooped::class);
    }

    #[RunInSeparateProcess]
    public function testMasterTerminationReturnsItsStatusAndSkipsLaterCommands(): void
    {
        Event::fake([MasterSupervisorLooped::class]);
        $master = new MasterSupervisorWithPendingTermination;
        app(HorizonCommandQueue::class)->push(
            $master->commandQueue(),
            AddSupervisor::class,
            (new SupervisorOptions('unwanted-supervisor', 'redis'))->toArray(),
        );

        $this->assertSame(12, $master->monitor());
        $this->assertCount(0, $master->supervisors);
        $this->assertNull(app(MasterSupervisorRepository::class)->find($master->name));
        Event::assertNotDispatched(MasterSupervisorLooped::class);
    }
}

class MasterSupervisorWithPendingTermination extends MasterSupervisor
{
    protected function listenForSignals(): void
    {
        $this->pendingSignals['terminateWithStatus'] = 'terminateWithStatus';
    }

    protected function terminateWithStatus(): void
    {
        $this->terminate(12);
    }
}
