<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Events\UnableToLaunchProcess;
use Hypervel\Horizon\Events\WorkerProcessRestarting;
use Hypervel\Tests\Horizon\IntegrationTest;
use Hypervel\Horizon\WorkerProcess;
use Hypervel\Support\Facades\Event;
use Symfony\Component\Process\Process;

/**
 * @internal
 * @coversNothing
 */
class WorkerProcessTest extends IntegrationTest
{
    public function testWorkerProcessFiresEventIfStoppedProcessCantBeRestarted()
    {
        Event::fake();
        $process = Process::fromShellCommandline('exit 1');
        $workerProcess = new WorkerProcess($process);
        $workerProcess->start(function () {
        });
        sleep(1);
        $workerProcess->monitor();
        $workerProcess->stop();

        Event::assertDispatched(WorkerProcessRestarting::class);
        Event::assertDispatched(UnableToLaunchProcess::class);
    }

    public function testProcessIsNotRestartedDuringCooldownPeriod()
    {
        Event::fake();

        $process = Process::fromShellCommandline('exit 1');
        $workerProcess = new WorkerProcess($process);
        $workerProcess->start(function () {
        });
        sleep(1);
        $workerProcess->monitor();
        $workerProcess->monitor();
        $workerProcess->stop();

        Event::assertDispatched(WorkerProcessRestarting::class);
        $this->assertCount(1, Event::dispatched(WorkerProcessRestarting::class));
    }

    public function testProcessIsRestartedAfterCooldownPeriod()
    {
        Event::fake();

        $process = Process::fromShellCommandline('exit 1');
        $workerProcess = new WorkerProcess($process);
        $workerProcess->start(function () {
        });

        // Give process time to start...
        sleep(1);

        // Should fail and set cooldown timestamp...
        $workerProcess->monitor();
        $this->assertTrue($workerProcess->coolingDown());

        // Travel to the future...
        sleep(1);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));
        $this->assertFalse($workerProcess->coolingDown());

        // Should try to restart now...
        $workerProcess->monitor();
        $workerProcess->stop();

        Event::assertDispatched(WorkerProcessRestarting::class);
        $this->assertCount(2, Event::dispatched(WorkerProcessRestarting::class));

        CarbonImmutable::setTestNow();
    }
}
