<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\ProcessCollector;
use Hypervel\Support\Sleep;
use Hypervel\Tests\ServerProcess\Fixtures\FooProcess;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use ReflectionClass;
use RuntimeException;
use Swoole\Event as SwooleEvent;
use Swoole\Process as SwooleProcess;
use Swoole\Server;
use Swoole\Timer;
use Throwable;

class AbstractProcessTest extends TestCase
{
    use VerifiesDoubles;

    /** @var SwooleProcess[] */
    private array $nativeProcesses = [];

    // SwooleProcess creation fails with "unable to create Swoole\Process with async-io
    // threads" when the test runs inside a coroutine and multiple ParaTest workers create
    // processes simultaneously. These tests only verify callback logic and event dispatch,
    // not coroutine behavior, so opting out is safe.
    protected bool $runTestsInCoroutine = false;

    /**
     * Close native process pipes and drain their reactor cleanup.
     */
    protected function tearDown(): void
    {
        foreach ($this->nativeProcesses as $process) {
            @$process->close();
        }

        Timer::clearAll();
        // Drain the reactor so process pipe cleanup is not deferred to PHP shutdown.
        SwooleEvent::wait();
        FooProcess::$handled = false;

        parent::tearDown();
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $container = new Container;

        $process = new FooProcess($container);
        $server = Double::for(Server::class)->strict();

        $this->assertTrue($process->isEnabled($server));
    }

    public function testDefaultPropertyValues(): void
    {
        $container = new Container;

        $process = new FooProcess($container);

        $this->assertSame('process', $process->name);
        $this->assertSame(1, $process->processCount);
        $this->assertFalse($process->redirectStdinStdout);
        $this->assertSame(SOCK_DGRAM, $process->pipeType);
    }

    public function testBindCreatesProcessAndAddsToServer(): void
    {
        $container = new Container;

        $process = new FooProcess($container);

        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->resolves(function (SwooleProcess $swooleProcess) {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $property = $reflection->getProperty('callback');
            $callback = $property->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);

        $this->assertTrue(FooProcess::$handled);
    }

    public function testBindCreatesMultipleProcessesWhenProcessCountGreaterThanOne(): void
    {
        $container = new Container;

        $process = new FooProcess($container);
        $process->processCount = 3;

        $addCount = 0;
        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->times(3)->resolves(function (SwooleProcess $swooleProcess) use (&$addCount) {
            $this->nativeProcesses[] = $swooleProcess;
            return ++$addCount;
        });

        $process->bind($server);

        $this->assertSame(3, $addCount);
    }

    public function testBindDispatchesBeforeAndAfterEvents(): void
    {
        $dispatched = [];
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeProcessHandle::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(AfterProcessHandle::class)->returns(true);
        $dispatcher->expects('dispatch')->times(2)->resolves(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $container = new Container;
        $container->instance('events', $dispatcher);

        $process = new FooProcess($container);

        $server = Double::for(Server::class)->strict();
        $server->allows('addProcess')->resolves(function (SwooleProcess $swooleProcess) {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);

        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(BeforeProcessHandle::class, $dispatched[0]);
        $this->assertInstanceOf(AfterProcessHandle::class, $dispatched[1]);
        $this->assertSame($process, $dispatched[0]->process);
        $this->assertSame(0, $dispatched[0]->index);
    }

    public function testBindHandlesProcessWithoutLifecycleListeners(): void
    {
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeProcessHandle::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(AfterProcessHandle::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $container = new Container;
        $container->instance('events', $dispatcher);

        $process = new FooProcess($container);
        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->resolves(function (SwooleProcess $swooleProcess): int {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);

            return 1;
        });

        $process->bind($server);

        $this->assertTrue(FooProcess::$handled);
    }

    public function testBindDispatchesEventsWithCorrectIndices(): void
    {
        $dispatched = [];
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->times(2)->with(BeforeProcessHandle::class)->returns(true);
        $dispatcher->expects('hasListeners')->times(2)->with(AfterProcessHandle::class)->returns(true);
        $dispatcher->expects('dispatch')->times(4)->resolves(function ($event) use (&$dispatched) {
            $dispatched[] = $event;
        });

        $container = new Container;
        $container->instance('events', $dispatcher);

        $process = new FooProcess($container);
        $process->processCount = 2;

        $server = Double::for(Server::class)->strict();
        $server->allows('addProcess')->resolves(function (SwooleProcess $swooleProcess) {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);

        // 2 processes × 2 events (before+after) = 4 events
        $this->assertCount(4, $dispatched);
        $this->assertSame(0, $dispatched[0]->index); // Before process 0
        $this->assertSame(0, $dispatched[1]->index); // After process 0
        $this->assertSame(1, $dispatched[2]->index); // Before process 1
        $this->assertSame(1, $dispatched[3]->index); // After process 1
    }

    public function testLogThrowableReportsViaExceptionHandler(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report');

        $container = new Container;
        $container->instance(ExceptionHandlerContract::class, $handler);

        $process = new class($container) extends AbstractProcess {
            public bool $enableCoroutine = false;

            public int $restartInterval = 0;

            /**
             * Throw the process failure under test.
             */
            public function handle(): void
            {
                throw new RuntimeException('test error');
            }
        };

        $server = Double::for(Server::class)->strict();
        $server->allows('addProcess')->resolves(function (SwooleProcess $swooleProcess) {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        $process->bind($server);
    }

    public function testLogThrowableSilentlyIgnoresWhenNoExceptionHandler(): void
    {
        $container = new Container;

        $process = new class($container) extends AbstractProcess {
            public bool $enableCoroutine = false;

            public int $restartInterval = 0;

            /**
             * Throw the process failure under test.
             */
            public function handle(): void
            {
                throw new RuntimeException('test error');
            }
        };

        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->resolves(function (SwooleProcess $swooleProcess) {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);
            return 1;
        });

        // Should not throw — the exception is caught and silently ignored
        $process->bind($server);
    }

    public function testConstructorResolvesEventDispatcherIfAvailable(): void
    {
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $container = new Container;
        $container->instance('events', $dispatcher);

        $process = new FooProcess($container);

        $reflection = new ReflectionClass($process);
        $property = $reflection->getProperty('event');
        $this->assertSame($dispatcher, $property->getValue($process));
    }

    public function testConstructorSetsEventToNullWhenNotAvailable(): void
    {
        $container = new Container;

        $process = new FooProcess($container);

        $reflection = new ReflectionClass($process);
        $property = $reflection->getProperty('event');
        $this->assertNull($property->getValue($process));
    }

    public function testBindRejectsFailedNativeProcessRegistration(): void
    {
        $container = new Container;

        $process = new FooProcess($container);
        $process->enableCoroutine = true;
        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->resolves(function (SwooleProcess $swooleProcess): false {
            $this->nativeProcesses[] = $swooleProcess;

            return false;
        });

        try {
            $process->bind($server);
            $this->fail('Expected failed native registration to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to register server process [process.0].', $exception->getMessage());
        }

        $this->assertTrue(ProcessCollector::isEmpty());
        $this->assertFalse(@$this->nativeProcesses[0]->write('closed'));
    }

    public function testTeardownContinuesAfterAfterProcessEventFailure(): void
    {
        Sleep::fake();
        CoordinatorManager::initialize(Constants::WORKER_EXIT);
        $timerId = Timer::after(60_000, static fn (): null => null);
        $afterFailure = new RuntimeException('after event failed');

        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeProcessHandle::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(AfterProcessHandle::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(BeforeProcessHandle::class));
        $dispatcher->expects('dispatch')->with(Argument::type(AfterProcessHandle::class))->throws($afterFailure);

        $container = new Container;
        $container->instance('events', $dispatcher);

        $process = new FooProcess($container);
        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->resolves(function (SwooleProcess $swooleProcess): int {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);

            return 1;
        });

        $thrown = null;

        try {
            $process->bind($server);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertSame($afterFailure, $thrown);
        $this->assertFalse(Timer::exists($timerId));
        $this->assertTrue(CoordinatorManager::until(Constants::WORKER_EXIT)->isClosing());
        Sleep::assertSleptTimes(1);
    }

    public function testTeardownPreservesReporterFailureAfterLaterCleanupFailure(): void
    {
        Sleep::fake();
        $sleepFailure = new RuntimeException('sleep failed');
        Sleep::whenFakingSleep(static function () use ($sleepFailure): void {
            throw $sleepFailure;
        });
        CoordinatorManager::initialize(Constants::WORKER_EXIT);
        $timerId = Timer::after(60_000, static fn (): null => null);
        $operationFailure = new RuntimeException('operation failed');
        $reporterFailure = new RuntimeException('reporter failed');

        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeProcessHandle::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(AfterProcessHandle::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(BeforeProcessHandle::class));
        $dispatcher->expects('dispatch')->with(Argument::type(AfterProcessHandle::class));

        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with(Argument::same($operationFailure))->throws($reporterFailure);

        $container = new Container;
        $container->instance('events', $dispatcher);
        $container->instance(ExceptionHandlerContract::class, $handler);

        $process = new class($container, $operationFailure) extends AbstractProcess {
            public bool $enableCoroutine = false;

            public int $restartInterval = 1;

            /**
             * Create a process with the configured operation failure.
             */
            public function __construct(ContainerContract $container, private Throwable $failure)
            {
                parent::__construct($container);
            }

            /**
             * Throw the process failure under test.
             */
            public function handle(): void
            {
                throw $this->failure;
            }
        };

        $server = Double::for(Server::class)->strict();
        $server->expects('addProcess')->resolves(function (SwooleProcess $swooleProcess): int {
            $this->nativeProcesses[] = $swooleProcess;
            $reflection = new ReflectionClass($swooleProcess);
            $callback = $reflection->getProperty('callback')->getValue($swooleProcess);
            $callback($swooleProcess);

            return 1;
        });

        $thrown = null;

        try {
            $process->bind($server);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertSame($reporterFailure, $thrown);
        $this->assertFalse(Timer::exists($timerId));
        $this->assertTrue(CoordinatorManager::until(Constants::WORKER_EXIT)->isClosing());
        Sleep::assertSleptTimes(1);
    }
}
