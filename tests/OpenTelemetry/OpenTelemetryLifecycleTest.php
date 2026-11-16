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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;
use Swoole\Server;

class OpenTelemetryLifecycleTest extends TestCase
{
    use VerifiesDoubles;

    public function testBindsTheCorrectWorkerIdentityAndStartsOneScheduler(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('bind')->with(Argument::satisfies(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::TASK
                && $identity->workerId === 7,
        ));
        $manager->expects('isBound')->returns(true);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('start');
        $server = Double::for(Server::class)->strict();
        $server->taskworker = true;

        $this->lifecycle($manager, $scheduler)->startWorker(new AfterWorkerStart($server, 7));
    }

    public function testBindsAnEventWorkerIdentity(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('bind')->with(Argument::satisfies(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::EVENT
                && $identity->workerId === 3,
        ));
        $manager->expects('isBound')->returns(true);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('start');
        $server = Double::for(Server::class)->strict();
        $server->taskworker = false;

        $this->lifecycle($manager, $scheduler)->startWorker(new AfterWorkerStart($server, 3));
    }

    public function testWorkerRetainsShutdownOwnershipAfterRegistrationFailure(): void
    {
        $expected = new RuntimeException('Unable to register instrumentation.');
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('bind')->throws($expected);
        $manager->expects('isBound')->returns(true);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('start');
        $server = Double::for(Server::class)->strict();
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
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->times(3)->returns(false, true, true);
        $manager->expects('bind')->with(Argument::satisfies(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::CLI,
        ));
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('start');
        $scheduler->expects('stop');
        $scheduler->expects('shutdown')->returns(true);
        $terminatingListener = null;
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->with(Terminating::class, Argument::capture($terminatingListener));
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
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->times(2)->returns(false, true);
        $manager->expects('bind')->throws($expected);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('shutdown')->returns(true);
        $terminatingListener = null;
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->with(Terminating::class, Argument::capture($terminatingListener));
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
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('bind')->with(Argument::satisfies(
            static fn (ProcessIdentity $identity): bool => $identity->type === ProcessIdentity::PROCESS
                && $identity->processClass === LifecycleProcess::class
                && $identity->processName === 'telemetry-test'
                && $identity->processIndex === 2,
        ));
        $manager->expects('isBound')->returns(true);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('start');
        $scheduler->expects('shutdown')->returns(true);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('bound')->with('events')->returns(false);
        $process = new LifecycleProcess($container->instance());
        $process->name = 'telemetry-test';

        $lifecycle = $this->lifecycle($manager, $scheduler);
        $lifecycle->startProcess(new BeforeProcessHandle($process, 2));
        $lifecycle->finishProcess(new AfterProcessHandle($process, 2));
    }

    public function testNonCoroutineServerProcessDrainsWithoutStartingATimer(): void
    {
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('bind');
        $manager->expects('isBound')->returns(true);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('shutdown')->returns(true);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('bound')->with('events')->returns(false);
        $process = new LifecycleProcess($container->instance());
        $process->enableCoroutine = false;

        $lifecycle = $this->lifecycle($manager, $scheduler);
        $lifecycle->startProcess(new BeforeProcessHandle($process, 0));
        $lifecycle->finishProcess(new AfterProcessHandle($process, 0));
    }

    public function testExcludedAndFailedProcessesHaveNoCleanupLifecycle(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('bound')->with('events')->returns(false);
        $process = new LifecycleProcess($container->instance());
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('isBound')->returns(false);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $configuration = ['opentelemetry' => ['server_processes' => ['except' => [LifecycleProcess::class]]]];
        $lifecycle = $this->lifecycle($manager, $scheduler, $configuration);

        $lifecycle->startProcess(new BeforeProcessHandle($process, 0));
        $lifecycle->finishProcess(new AfterProcessHandle($process, 0));

        $expected = new RuntimeException('Unable to bind process telemetry.');
        $failingManager = Double::for(OpenTelemetryManager::class)->strict();
        $failingManager->expects('bind')->throws($expected);
        $failingManager->expects('isBound')->returns(false);
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
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('bound')->with('events')->returns(false);
        $process = new LifecycleProcess($container->instance());
        $expected = new RuntimeException('Unable to register instrumentation.');
        $manager = Double::for(OpenTelemetryManager::class)->strict();
        $manager->expects('bind')->throws($expected);
        $manager->expects('isBound')->returns(true);
        $scheduler = Double::for(ExportScheduler::class)->strict();
        $scheduler->expects('shutdown')->returns(true);
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
            $events ?? Double::for(Dispatcher::class)->strict(),
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
