<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandlerInterface as SignalHandler;
use Hypervel\Engine\Channel;
use Hypervel\Signal\SignalManager;
use Hypervel\Tests\Signal\Fixtures\SignalHandler2Stub;
use Hypervel\Tests\Signal\Fixtures\SignalHandlerStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine as SwooleCoroutine;

class SignalManagerTest extends TestCase
{
    public function testGetHandlers(): void
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturnUsing(function (): Repository {
            return new Repository([
                'signal' => [
                    'handlers' => [
                        SignalHandlerStub::class,
                        SignalHandler2Stub::class => 1,
                    ],
                ],
            ]);
        });
        $manager = new SignalManager($container);
        $manager->init();

        $this->assertArrayHasKey(SignalHandler::WORKER, $manager->getHandlers());
        $this->assertArrayHasKey(SIGTERM, $manager->getHandlers()[SignalHandler::WORKER]);
        $this->assertIsArray($manager->getHandlers()[SignalHandler::WORKER]);
        $this->assertInstanceOf(SignalHandler2Stub::class, $manager->getHandlers()[SignalHandler::WORKER][SIGTERM][0]);
        $this->assertInstanceOf(SignalHandlerStub::class, $manager->getHandlers()[SignalHandler::WORKER][SIGTERM][1]);
    }

    public function testInitReplacesExistingHandlers(): void
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturnUsing(function (): Repository {
            return new Repository([
                'signal' => [
                    'handlers' => [
                        SignalHandlerStub::class,
                        SignalHandler2Stub::class,
                    ],
                ],
            ]);
        });

        $manager = new SignalManager($container);
        $manager->init();
        $manager->init();

        $this->assertCount(2, $manager->getHandlers()[SignalHandler::WORKER][SIGTERM]);
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
            public function listen(): array
            {
                return [
                    [self::WORKER, SIGUSR1],
                    [self::WORKER, SIGUSR2],
                ];
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

            public function listen(): array
            {
                return [
                    [self::WORKER, SIGUSR1],
                ];
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
            usleep(1_000);
            $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
        } finally {
            $manager->stop();
            $continueHandler->push(true, 0.01);
            usleep(1_000);
            $handlerStarted->close();
            $continueHandler->close();
            $handlerFinished->close();
        }
    }

    public function testListenAfterStopDoesNotSpawnSignalWatchers(): void
    {
        $manager = $this->createManager(new SignalHandlerStub);
        $manager->stop();
        $coroutinesBeforeListen = SwooleCoroutine::stats()['coroutine_num'];

        $manager->listen(SignalHandler::WORKER);

        $this->assertSame($coroutinesBeforeListen, SwooleCoroutine::stats()['coroutine_num']);
    }

    public function testSignalHandlerInterfaceConstantsHaveExpectedValues(): void
    {
        $this->assertSame(1, SignalHandler::WORKER);
        $this->assertSame(2, SignalHandler::PROCESS);
    }

    public function testInitWithNoHandlersConfigured(): void
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([]));

        $manager = new SignalManager($container);
        $manager->init();

        $this->assertEmpty($manager->getHandlers());
    }

    protected function createManager(SignalHandler $handler): SignalManager
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([
            'signal' => [
                'handlers' => [$handler::class],
            ],
        ]));
        $container->shouldReceive('make')->with($handler::class)->andReturn($handler);

        $manager = new SignalManager($container);
        $manager->init();

        return $manager;
    }

    protected function getContainer(): ContainerContract
    {
        $container = m::mock(ContainerContract::class);

        $container->shouldReceive('make')->with(SignalHandlerStub::class)->andReturnUsing(function (): SignalHandlerStub {
            return new SignalHandlerStub;
        });
        $container->shouldReceive('make')->with(SignalHandler2Stub::class)->andReturnUsing(function (): SignalHandler2Stub {
            return new SignalHandler2Stub;
        });

        return $container;
    }
}
