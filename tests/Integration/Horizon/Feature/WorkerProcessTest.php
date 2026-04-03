<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Events\UnableToLaunchProcess;
use Hypervel\Horizon\Events\WorkerProcessRestarting;
use Hypervel\Horizon\WorkerProcess;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Symfony\Component\Process\Process;

/**
 * @internal
 * @coversNothing
 */
class WorkerProcessTest extends IntegrationTestCase
{
    public function testWorkerProcessFiresEventIfStoppedProcessCantBeRestarted()
    {
        Event::fake();
        $process = Process::fromShellCommandline('exit 1');
        $workerProcess = new WorkerProcess($process);
        CarbonImmutable::setTestNow($time = CarbonImmutable::create(2026, 1, 1, 0, 0, 0));

        try {
            $workerProcess->start(function () {
            });
            $this->waitForProcessToExit($process);

            CarbonImmutable::setTestNow($time->addSeconds(2));
            $workerProcess->monitor();
            $workerProcess->stop();

            Event::assertDispatched(WorkerProcessRestarting::class);
            Event::assertDispatched(UnableToLaunchProcess::class);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testProcessIsNotRestartedDuringCooldownPeriod()
    {
        Event::fake();

        $process = Process::fromShellCommandline('exit 1');
        $workerProcess = new WorkerProcess($process);
        CarbonImmutable::setTestNow($time = CarbonImmutable::create(2026, 1, 1, 0, 0, 0));

        try {
            $workerProcess->start(function () {
            });
            $this->waitForProcessToExit($process);

            CarbonImmutable::setTestNow($time->addSeconds(2));
            $workerProcess->monitor();
            $this->waitForProcessToExit($process);

            $workerProcess->monitor();
            $workerProcess->stop();

            Event::assertDispatched(WorkerProcessRestarting::class);
            $this->assertCount(1, Event::dispatched(WorkerProcessRestarting::class));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testProcessIsRestartedAfterCooldownPeriod()
    {
        Event::fake();

        $process = Process::fromShellCommandline('exit 1');
        $workerProcess = new WorkerProcess($process);
        CarbonImmutable::setTestNow($time = CarbonImmutable::create(2026, 1, 1, 0, 0, 0));

        try {
            $workerProcess->start(function () {
            });
            $this->waitForProcessToExit($process);

            CarbonImmutable::setTestNow($time->addSeconds(2));
            $workerProcess->monitor();
            $this->assertTrue($workerProcess->coolingDown());
            $this->waitForProcessToExit($process);

            CarbonImmutable::setTestNow($time->addMinutes(3));
            $this->assertFalse($workerProcess->coolingDown());

            $workerProcess->monitor();
            $workerProcess->stop();

            Event::assertDispatched(WorkerProcessRestarting::class);
            $this->assertCount(2, Event::dispatched(WorkerProcessRestarting::class));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    protected function waitForProcessToExit(Process $process): void
    {
        $this->wait(function () use ($process) {
            $this->assertTrue($process->isStarted());
            $this->assertFalse($process->isRunning());
            $this->assertNotNull($process->getExitCode());
        });
    }
}
