<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use ArrayObject;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Engine\Channel;
use Hypervel\Signal\SignalManager;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\Signal\Fixtures\SignalHandlerStub;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;

class SignalManagerTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testHigherPriorityHandlersContinueAfterFailureAndWatchAgain(): void
    {
        $trace = new ArrayObject;
        $handled = new Channel(2);
        $recordingHandler = new class($trace, $handled) implements SignalHandler {
            public function __construct(
                protected ArrayObject $trace,
                protected Channel $handled,
            ) {
            }

            public function signals(): array
            {
                return [self::WORKER => [SIGUSR1]];
            }

            public function handle(int $signal): void
            {
                $this->trace[] = 'recorded';
                $this->handled->push(true);
            }
        };
        $throwingHandler = new class($trace) implements SignalHandler {
            public function __construct(protected ArrayObject $trace)
            {
            }

            public function signals(): array
            {
                return [self::WORKER => [SIGUSR1]];
            }

            public function handle(int $signal): void
            {
                $this->trace[] = 'threw';

                throw new RuntimeException('Signal handler failed.');
            }
        };
        $exceptionHandler = m::mock(ExceptionHandlerContract::class);
        $exceptionHandler->shouldReceive('report')
            ->twice()
            ->with(m::type(RuntimeException::class));
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance(ConfigContract::class, new Repository([
            'signal' => [
                'handlers' => [
                    $recordingHandler::class,
                    $throwingHandler::class => '10',
                ],
            ],
        ]));
        $container->instance(ExceptionHandlerContract::class, $exceptionHandler);
        $container->instance($recordingHandler::class, $recordingHandler);
        $container->instance($throwingHandler::class, $throwingHandler);
        $manager = new SignalManager($container);

        try {
            $manager->listen(SignalHandler::WORKER);

            $this->assertTrue(posix_kill(getmypid(), SIGUSR1));
            $this->assertTrue($handled->pop(0.5));
            $this->assertSame(['threw', 'recorded'], $trace->getArrayCopy());

            // The channel wakes this test before the watcher returns from handle;
            // yield so it can re-arm before another process signal is delivered.
            SwooleCoroutine::sleep(0.005);

            $this->assertTrue(posix_kill(getmypid(), SIGUSR1));
            $this->assertTrue($handled->pop(0.5));
            $this->assertSame(['threw', 'recorded', 'threw', 'recorded'], $trace->getArrayCopy());
        } finally {
            $manager->stop();
            $handled->close();
        }
    }

    public function testGroupedDefinitionsCreateOnlyRequestedSignalWatchers(): void
    {
        $handler = new class implements SignalHandler {
            public function signals(): array
            {
                return [
                    self::WORKER => [SIGUSR1],
                    self::SERVER_PROCESS => [SIGUSR2],
                ];
            }

            public function handle(int $signal): void
            {
            }
        };
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];
        $workerManager = $this->createManager($handler);

        try {
            $workerManager->listen(SignalHandler::WORKER);

            $this->assertSame($coroutinesBeforeListen + 1, SwooleCoroutine::stats()['coroutine_num']);
        } finally {
            $workerManager->stop();
        }

        $serverProcessManager = $this->createManager($handler);

        try {
            $serverProcessManager->listen(SignalHandler::SERVER_PROCESS);

            $this->assertSame($coroutinesBeforeListen + 1, SwooleCoroutine::stats()['coroutine_num']);
        } finally {
            $serverProcessManager->stop();
        }
    }

    public function testRejectsUnsupportedProcess(): void
    {
        $manager = $this->createManagerFromConfig([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported signal process [workers]. Supported processes are [worker] and [server-process].',
        );

        $manager->listen('workers');
    }

    public function testRejectsHandlerThatDoesNotImplementContract(): void
    {
        $handler = new class {
            public function signals(): array
            {
                return [SignalHandler::WORKER => [SIGUSR1]];
            }

            public function handle(int $signal): void
            {
            }
        };
        $manager = $this->createManagerFromConfig([$handler::class], [$handler::class => $handler]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement [Hypervel\Contracts\Signal\SignalHandler]');

        $manager->listen(SignalHandler::WORKER);
    }

    public function testRejectsUnsupportedHandlerProcessEvenWhenListeningForAnotherProcess(): void
    {
        $handler = new class implements SignalHandler {
            public function signals(): array
            {
                return [
                    self::WORKER => [SIGUSR1],
                    'process' => [SIGUSR2],
                ];
            }

            public function handle(int $signal): void
            {
            }
        };
        $manager = $this->createManager($handler);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'declares unsupported process [process]. Supported processes are [worker] and [server-process].',
        );

        $manager->listen(SignalHandler::WORKER);
    }

    public function testRejectsNonArraySignalGroupEvenWhenListeningForAnotherProcess(): void
    {
        $handler = new class implements SignalHandler {
            public function signals(): array
            {
                return [
                    self::WORKER => [SIGUSR1],
                    self::SERVER_PROCESS => SIGUSR2,
                ];
            }

            public function handle(int $signal): void
            {
            }
        };
        $manager = $this->createManager($handler);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'must declare an array of signal numbers for the [server-process] process.',
        );

        $manager->listen(SignalHandler::WORKER);
    }

    public function testRejectsNonIntegerSignal(): void
    {
        $handler = new class implements SignalHandler {
            public function signals(): array
            {
                return [self::WORKER => [SIGUSR1, 'SIGUSR2']];
            }

            public function handle(int $signal): void
            {
            }
        };
        $manager = $this->createManager($handler);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare an array of signal numbers for the [worker] process.');

        $manager->listen(SignalHandler::WORKER);
    }

    public function testAllowsEmptySignalGroup(): void
    {
        $handler = new class implements SignalHandler {
            public function signals(): array
            {
                return [self::WORKER => []];
            }

            public function handle(int $signal): void
            {
            }
        };
        $manager = $this->createManager($handler);
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        $manager->listen(SignalHandler::WORKER);

        $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
    }

    public function testRejectsNonStringListEntry(): void
    {
        $manager = $this->createManagerFromConfig([[]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Signal handler at index [0] must be a class name.');

        $manager->listen(SignalHandler::WORKER);
    }

    public function testRejectsNonnumericPriority(): void
    {
        $manager = $this->createManagerFromConfig([SignalHandlerStub::class => 'high']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The priority for signal handler [Hypervel\Tests\Signal\Fixtures\SignalHandlerStub] must be numeric.',
        );

        $manager->listen(SignalHandler::WORKER);
    }

    public function testStopReleasesWaitingSignalWatchers(): void
    {
        $manager = $this->createManager(new SignalHandlerStub);
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        try {
            $manager->listen(SignalHandler::WORKER);

            $this->assertSame($coroutinesBeforeListen + 1, SwooleCoroutine::stats()['coroutine_num']);

            $manager->stop();

            $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
        } finally {
            $manager->stop();
        }
    }

    public function testStopReleasesEveryWaitingSignalWatcher(): void
    {
        $handler = new class implements SignalHandler {
            public function signals(): array
            {
                return [self::WORKER => [SIGUSR1, SIGUSR2]];
            }

            public function handle(int $signal): void
            {
            }
        };
        $manager = $this->createManager($handler);
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        try {
            $manager->listen(SignalHandler::WORKER);

            $this->assertSame($coroutinesBeforeListen + 2, SwooleCoroutine::stats()['coroutine_num']);

            $manager->stop();

            $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
        } finally {
            $manager->stop();
        }
    }

    #[RunInSeparateProcess]
    public function testStopDoesNotInterruptAnActiveSignalHandler(): void
    {
        $handlerStarted = new Channel(1);
        $continueHandler = new Channel(1);
        $handlerFinished = new Channel(1);
        $handler = new class($handlerStarted, $continueHandler, $handlerFinished) implements SignalHandler {
            public function __construct(
                protected Channel $handlerStarted,
                protected Channel $continueHandler,
                protected Channel $handlerFinished,
            ) {
            }

            public function signals(): array
            {
                return [self::WORKER => [SIGUSR1]];
            }

            public function handle(int $signal): void
            {
                $this->handlerStarted->push(true);
                $this->continueHandler->pop(1.0);
                $this->handlerFinished->push(true);
            }
        };
        $manager = $this->createManager($handler);
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        try {
            $manager->listen(SignalHandler::WORKER);

            $this->assertTrue(posix_kill(getmypid(), SIGUSR1));
            $this->assertTrue($handlerStarted->pop(0.5));

            $manager->stop();
            $continueHandler->push(true);

            $this->assertTrue($handlerFinished->pop(0.5));
            SwooleCoroutine::sleep(0.001);
            $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
        } finally {
            $manager->stop();
            $continueHandler->push(true, 0.01);
            SwooleCoroutine::sleep(0.001);
            $handlerStarted->close();
            $continueHandler->close();
            $handlerFinished->close();
        }
    }

    public function testListenAfterStopDoesNotResolveHandlersOrSpawnWatchers(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([
            'signal' => ['handlers' => [SignalHandlerStub::class]],
        ]));
        $container->shouldReceive('make')->with(SafeCaller::class)->andReturn(new SafeCaller($container));
        $container->shouldNotReceive('make')->with(SignalHandlerStub::class);
        $manager = new SignalManager($container);
        $manager->stop();
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        $manager->listen(SignalHandler::WORKER);

        $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
    }

    public function testNoConfiguredHandlersSpawnNoWatchers(): void
    {
        $manager = $this->createManagerFromConfig([]);
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        $manager->listen(SignalHandler::WORKER);

        $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
    }

    protected function createManager(SignalHandler $handler): SignalManager
    {
        return $this->createManagerFromConfig(
            [$handler::class],
            [$handler::class => $handler],
        );
    }

    /**
     * Create a signal manager from the given handler configuration.
     *
     * @param array<array-key, mixed> $handlers
     * @param array<class-string, object> $instances
     */
    protected function createManagerFromConfig(array $handlers, array $instances = []): SignalManager
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([
            'signal' => ['handlers' => $handlers],
        ]));
        $container->shouldReceive('make')->with(SafeCaller::class)->andReturn(new SafeCaller($container));

        foreach ($instances as $class => $instance) {
            $container->shouldReceive('make')->with($class)->andReturn($instance);
        }

        return new SignalManager($container);
    }
}
