<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\OpenTelemetry\OpenTelemetryLifecycle;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExportScheduler;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Server;

class OpenTelemetryLifecycleTest extends TestCase
{
    public function testBindsTheCorrectWorkerIdentityAndStartsOneScheduler(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('bind')->once()->withArgs(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::TASK
                && $identity->workerId === 7,
        );
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('start')->once();
        $server = m::mock(Server::class);
        $server->taskworker = true;

        $this->lifecycle($manager, $scheduler)->startWorker(new AfterWorkerStart($server, 7));
    }

    public function testBindsAnEventWorkerIdentity(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('bind')->once()->withArgs(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::EVENT
                && $identity->workerId === 3,
        );
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('start')->once();
        $server = m::mock(Server::class);
        $server->taskworker = false;

        $this->lifecycle($manager, $scheduler)->startWorker(new AfterWorkerStart($server, 3));
    }

    public function testWorkerRetainsShutdownOwnershipAfterRegistrationFailure(): void
    {
        $expected = new RuntimeException('Unable to register instrumentation.');
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('bind')->once()->andThrow($expected);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('start')->once();
        $server = m::mock(Server::class);
        $server->taskworker = false;

        try {
            $this->lifecycle($manager, $scheduler)->startWorker(new AfterWorkerStart($server, 3));
            $this->fail('A worker instrumentation-registration failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }
    }

    public function testStandaloneCliBindsOnceAndSharesOneTimerAcrossNestedCommands(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->times(3)->andReturn(false, true, true);
        $manager->shouldReceive('bind')->once()->withArgs(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::CLI,
        );
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('start')->once();
        $scheduler->shouldReceive('stop')->once();
        $scheduler->shouldReceive('shutdown')->once()->andReturnTrue();
        $terminatingListener = null;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->once()
            ->with(Terminating::class, m::on(function (callable $listener) use (&$terminatingListener): bool {
                $terminatingListener = $listener;

                return true;
            }));
        $lifecycle = $this->lifecycle($manager, $scheduler, events: $events);

        $lifecycle->startCli();
        $lifecycle->startCli();
        $lifecycle->beginCliCommand();
        $lifecycle->beginCliCommand();
        $lifecycle->endCliCommand();
        $lifecycle->endCliCommand();

        $this->assertIsCallable($terminatingListener);
        $terminatingListener(new Terminating);
    }

    public function testStandaloneCliRetainsShutdownOwnershipAfterRegistrationFailure(): void
    {
        $expected = new RuntimeException('Unable to register instrumentation.');
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->twice()->andReturn(false, true);
        $manager->shouldReceive('bind')->once()->andThrow($expected);
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('shutdown')->once()->andReturnTrue();
        $terminatingListener = null;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->once()
            ->with(Terminating::class, m::on(function (callable $listener) use (&$terminatingListener): bool {
                $terminatingListener = $listener;

                return true;
            }));
        $lifecycle = $this->lifecycle($manager, $scheduler, events: $events);

        try {
            $lifecycle->startCli();
            $this->fail('A CLI instrumentation-registration failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertIsCallable($terminatingListener);
        $terminatingListener(new Terminating);
    }

    public function testCoroutineServerProcessBindsItsIdentityAndClosesAtItsOwnBoundary(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('bind')->once()->withArgs(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::PROCESS
                && $identity->processClass === LifecycleProcess::class
                && $identity->processName === 'telemetry-test'
                && $identity->processIndex === 2,
        );
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('start')->once();
        $scheduler->shouldReceive('shutdown')->once()->andReturnTrue();
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->once()->with('events')->andReturnFalse();
        $process = new LifecycleProcess($container);
        $process->name = 'telemetry-test';

        $lifecycle = $this->lifecycle($manager, $scheduler);
        $lifecycle->startProcess(new BeforeProcessHandle($process, 2));
        $lifecycle->finishProcess(new AfterProcessHandle($process, 2));
    }

    public function testNonCoroutineServerProcessDrainsWithoutStartingATimer(): void
    {
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('bind')->once();
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('shutdown')->once()->andReturnTrue();
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->once()->with('events')->andReturnFalse();
        $process = new LifecycleProcess($container);
        $process->enableCoroutine = false;

        $lifecycle = $this->lifecycle($manager, $scheduler);
        $lifecycle->startProcess(new BeforeProcessHandle($process, 0));
        $lifecycle->finishProcess(new AfterProcessHandle($process, 0));
    }

    public function testExcludedAndFailedProcessesHaveNoCleanupLifecycle(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->once()->with('events')->andReturnFalse();
        $process = new LifecycleProcess($container);
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('isBound')->once()->andReturnFalse();
        $scheduler = m::mock(ExportScheduler::class);
        $configuration = ['opentelemetry' => ['server_processes' => ['except' => [LifecycleProcess::class]]]];
        $lifecycle = $this->lifecycle($manager, $scheduler, $configuration);

        $lifecycle->startProcess(new BeforeProcessHandle($process, 0));
        $lifecycle->finishProcess(new AfterProcessHandle($process, 0));

        $expected = new RuntimeException('Unable to bind process telemetry.');
        $failingManager = m::mock(OpenTelemetryManager::class);
        $failingManager->shouldReceive('bind')->once()->andThrow($expected);
        $failingManager->shouldReceive('isBound')->once()->andReturnFalse();
        $failingLifecycle = $this->lifecycle($failingManager, $scheduler);

        try {
            $failingLifecycle->startProcess(new BeforeProcessHandle($process, 0));
            $this->fail('A process bind failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $failingLifecycle->finishProcess(new AfterProcessHandle($process, 0));
    }

    public function testProcessRegistrationFailureClosesTheBoundGraph(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->once()->with('events')->andReturnFalse();
        $process = new LifecycleProcess($container);
        $expected = new RuntimeException('Unable to register instrumentation.');
        $manager = m::mock(OpenTelemetryManager::class);
        $manager->shouldReceive('bind')->once()->andThrow($expected);
        $manager->shouldReceive('isBound')->once()->andReturnTrue();
        $scheduler = m::mock(ExportScheduler::class);
        $scheduler->shouldReceive('shutdown')->once()->andReturnTrue();
        $lifecycle = $this->lifecycle($manager, $scheduler);

        try {
            $lifecycle->startProcess(new BeforeProcessHandle($process, 0));
            $this->fail('A process instrumentation-registration failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $lifecycle->finishProcess(new AfterProcessHandle($process, 0));
    }

    /**
     * Create a lifecycle coordinator for testing.
     */
    private function lifecycle(
        OpenTelemetryManager $manager,
        ExportScheduler $scheduler,
        array $configuration = ['opentelemetry' => ['server_processes' => ['except' => []]]],
        ?Dispatcher $events = null,
    ): OpenTelemetryLifecycle {
        return new OpenTelemetryLifecycle(
            $manager,
            $scheduler,
            new Repository($configuration),
            $events ?? m::mock(Dispatcher::class),
        );
    }
}

class LifecycleProcess extends AbstractProcess
{
    /**
     * Handle the test process.
     */
    public function handle(): void
    {
    }
}
