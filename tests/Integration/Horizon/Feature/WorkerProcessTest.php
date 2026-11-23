<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Events\UnableToLaunchProcess;
use Hypervel\Horizon\Events\WorkerProcessRestarting;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorProcess;
use Hypervel\Horizon\WorkerProcess;
use Hypervel\Queue\Worker as QueueWorker;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

class WorkerProcessTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testWorkerProcessSkipsOptionalEventsWhenTheyHaveNoListeners(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(WorkerProcessRestarting::class)->returns(false);
        $events->expects('hasListeners')->with(UnableToLaunchProcess::class)->returns(false);
        $events->expects('dispatch')->never();
        $this->app->instance('events', $events);

        $restartProcess = Double::for(Process::class)->strict();
        // Symfony's destructor calls stop() even for these unstarted process doubles.
        $restartProcess->allows('stop')->with(0.0);
        $restartProcess->expects('isStarted')->returns(true);
        $restartProcess->expects('start');
        $restartWorker = new WorkerProcess($restartProcess);
        $restartWorker->handleOutputUsing(static function (): void {
        });
        (new ReflectionMethod(WorkerProcess::class, 'restart'))->invoke($restartWorker);

        $failedProcess = Double::for(Process::class)->strict();
        $failedProcess->allows('stop')->with(0.0);
        $failedProcess->expects('isRunning')->times(2)->returns(false);
        $failedWorker = new WorkerProcess($failedProcess);
        $failedWorker->restartAgainAt = CarbonImmutable::now()->subSecond();
        (new ReflectionMethod(WorkerProcess::class, 'cooldown'))->invoke($failedWorker);
    }

    public function testControlSignalSentDuringBootstrapIsDeliveredAfterHandlerInstallation(): void
    {
        $script = <<<'PHP'
        pcntl_async_signals(true);
        usleep(200_000);
        $received = false;
        pcntl_signal(SIGUSR2, static function () use (&$received): void {
            $received = true;
        });
        pcntl_sigprocmask(SIG_UNBLOCK, [SIGUSR2]);
        $deadline = microtime(true) + 1.0;
        while (! $received && microtime(true) < $deadline) {
            usleep(1_000);
        }
        fwrite(STDOUT, $received ? 'received' : 'missing');
        PHP;
        $output = '';
        $process = new WorkerProcess(new Process([PHP_BINARY, '-r', $script]));

        try {
            $process->start(static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            });

            $process->pause();
            $exitCode = $process->wait();

            $this->assertSame(0, $exitCode);
            $this->assertSame('received', $output);
        } finally {
            $process->kill();
        }
    }

    public function testStartRestoresTheParentSignalMaskAfterSuccess(): void
    {
        $before = $this->signalMask();
        $during = [];
        $process = Double::for(Process::class)->strict();
        $process->allows('stop')->with(0.0);
        $process->expects('start')->with(Argument::type(Closure::class))->resolves(
            static function () use (&$during): void {
                $during = self::signalMaskStatically();
            },
        );

        (new WorkerProcess($process))->start(static function (): void {
        });

        $expectedDuring = array_values(array_unique([
            ...$before,
            ...$this->queueWorkerSignals(),
        ]));
        sort($expectedDuring);

        $this->assertSame($expectedDuring, $during);
        $this->assertSame($before, $this->signalMask());
    }

    public function testStartRestoresTheParentSignalMaskWhenStartThrows(): void
    {
        $before = $this->signalMask();
        $failure = new RuntimeException('start failed');
        $process = Double::for(Process::class)->strict();
        $process->allows('stop')->with(0.0);
        $process->expects('start')->throws($failure);

        try {
            (new WorkerProcess($process))->start(static function (): void {
            });
            $this->fail('Expected process startup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($before, $this->signalMask());
    }

    public function testParentBlockSetsMatchTheChildHandlerSets(): void
    {
        $this->assertSame(
            $this->constant(QueueWorker::class, 'HANDLED_SIGNALS'),
            $this->constant(WorkerProcess::class, 'STARTUP_SIGNALS'),
        );
        $this->assertSame(
            $this->constant(Supervisor::class, 'HANDLED_SIGNALS'),
            $this->constant(SupervisorProcess::class, 'STARTUP_SIGNALS'),
        );
        $this->assertSame(
            $this->constant(Supervisor::class, 'HANDLED_SIGNALS'),
            $this->constant(MasterSupervisor::class, 'HANDLED_SIGNALS'),
        );
    }

    public function testKillStopsARunningProcessImmediately(): void
    {
        $timeouts = [];
        $process = Double::for(Process::class)->strict();
        $process->expects('isRunning')->returns(true);
        $process->allows('stop')->resolves(static function (float $timeout) use (&$timeouts): int {
            $timeouts[] = $timeout;

            return 0;
        });

        (new WorkerProcess($process))->kill();

        // Check kill() before Symfony's destructor makes its own stop() call.
        $this->assertSame([0.0], $timeouts);
    }

    public function testWorkerProcessFiresEventIfStoppedProcessCantBeRestarted(): void
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
            $workerProcess->kill();
            CarbonImmutable::setTestNow();
        }
    }

    public function testProcessIsNotRestartedDuringCooldownPeriod(): void
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
            $workerProcess->kill();
            CarbonImmutable::setTestNow();
        }
    }

    public function testProcessIsRestartedAfterCooldownPeriod(): void
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
            $workerProcess->kill();
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

    /**
     * Read the current process signal mask in stable numeric order.
     *
     * @return list<int>
     */
    private function signalMask(): array
    {
        return self::signalMaskStatically();
    }

    /**
     * @return list<int>
     */
    private static function signalMaskStatically(): array
    {
        $mask = [];

        if (! pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $mask)) {
            throw new RuntimeException('Unable to read the process signal mask.');
        }

        if (! pcntl_sigprocmask(SIG_SETMASK, $mask)) {
            throw new RuntimeException('Unable to restore the process signal mask.');
        }

        sort($mask);

        return $mask;
    }

    /**
     * @return list<int>
     */
    private function queueWorkerSignals(): array
    {
        $signals = $this->constant(QueueWorker::class, 'HANDLED_SIGNALS');
        sort($signals);

        return $signals;
    }

    /**
     * Read a protected signal-set constant.
     *
     * @param class-string $class
     * @return list<int>
     */
    private function constant(string $class, string $name): array
    {
        $value = (new ReflectionClass($class))->getConstant($name);

        $this->assertIsArray($value);

        return $value;
    }
}
