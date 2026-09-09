<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Hypervel\ConnectionPool\Exceptions\SocketPopException;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\ConnectionPool\Fixtures\HeartbeatPoolStub;
use Hypervel\Tests\ConnectionPool\Fixtures\KeepaliveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class KeepaliveConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::set('test.pool.heartbeat_connection', []);
        parent::tearDown();
    }

    public function testConnectionConstruct(): void
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        $connection = $pool->borrow();

        $this->assertInstanceOf(KeepaliveConnectionStub::class, $connection);
        $this->assertSame(1, $pool->getManagedCount());
        $this->assertSame(0, $pool->getIdleCount());

        $connection = $pool->borrow();
        $this->assertSame(2, $pool->getManagedCount());
        $this->assertSame(0, $pool->getIdleCount());

        $connection->release();
        $this->assertSame(1, $pool->getIdleCount());

        $connection = $pool->borrow();
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame(2, $pool->getManagedCount());
    }

    public function testConnectionAcceptsPoolContract(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $connection = new KeepaliveConnectionStub(
            m::mock(ContainerContract::class),
            $pool,
        );

        $pool->shouldReceive('release')->once()->with($connection);

        $connection->release();
    }

    public function testConnectionCall(): void
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->borrow();
        $connection->setActiveConnection(new class {
            public function send(string $data): string
            {
                return str_repeat($data, 2);
            }
        });
        $str = uniqid();
        $result = $connection->call(function ($connection) use ($str) {
            return $connection->send($str);
        });

        $this->assertSame($result, str_repeat($str, 2));
    }

    public function testDiscardDelegatesToOwningPool(): void
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        $connection = $pool->borrow();

        $connection->discard();

        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getIdleCount());
    }

    public function testConnectionHeartbeat(): void
    {
        $container = $this->getContainer(['heartbeat_interval' => 0.001]);
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->borrow();
        $connection->reconnect();
        $timer = $connection->timer;
        $this->assertSame(1, count((new ClassInvoker($timer))->coroutines));
        $this->assertTrue($connection->check());
        $connection->close();
        $this->assertSame(0, count((new ClassInvoker($timer))->coroutines));
        $this->assertFalse($connection->check());
        $this->assertSame('close protocol', CoroutineContext::get('test.pool.heartbeat_connection')['close']);
    }

    public function testDisabledHeartbeatDoesNotStartTimer(): void
    {
        $container = $this->getContainer([
            'heartbeat_interval' => null,
            'max_idle_time' => 0.001,
        ]);
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->borrow();
        $connection->reconnect();
        $timer = $connection->timer;

        $this->assertTrue($connection->check());
        $this->assertSame(0, count((new ClassInvoker($timer))->coroutines));

        Coroutine::sleep(0.01);

        $this->assertTrue($connection->check());
        $this->assertSame(0, $connection->closeCount);

        $connection->close();
    }

    public function testTimeoutRequiresAConnectedIdleSocket(): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test');
        $connection = $pool->borrow();
        $connection->setActiveConnection(new stdClass);

        try {
            $this->assertFalse($connection->isTimeout());

            $connection->reconnect();
            $this->assertFalse($connection->isTimeout());

            (new ReflectionProperty($connection, 'lastUseTime'))->setValue(
                $connection,
                hrtime(true) / 1e9 - $pool->getOptions()->maxIdleTime - 1.0,
            );
            $this->assertTrue($connection->isTimeout());

            $connection->call(function () use ($connection): void {
                $this->assertFalse($connection->isTimeout());
            }, false);
            $this->assertTrue($connection->isTimeout());

            $connection->close();
            $this->assertFalse($connection->isTimeout());
        } finally {
            $pool->discard($connection);
            $pool->close();
        }
    }

    public function testNullIdleTimeoutKeepsAnOldSocketOpen(): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', ['max_idle_time' => null]);
        $connection = $pool->borrow();
        $connection->reconnect();

        try {
            (new ReflectionProperty($connection, 'lastUseTime'))->setValue($connection, 0.0);

            $this->assertFalse($connection->isTimeout());
            $this->assertTrue($connection->isConnected());
        } finally {
            $connection->close();
            $pool->release($connection);
            $pool->close();
        }
    }

    public function testEnabledHeartbeatClosesIdleConnection(): void
    {
        $container = $this->getContainer([
            'heartbeat_interval' => 0.001,
            'max_idle_time' => 0.001,
        ]);
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->borrow();
        $connection->reconnect();

        Coroutine::sleep(0.01);

        $this->assertFalse($connection->check());
        $this->assertSame(1, $connection->closeCount);
    }

    public function testHeartbeatFailureFallsBackToThePhpErrorLogWithoutALogger(): void
    {
        $directory = ParallelTesting::tempDir('KeepaliveConnectionTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $previousLogErrors = ini_set('log_errors', '1');

        try {
            $container = $this->getContainer(['heartbeat_interval' => 0.001]);
            $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
            $pool = $container->make(HeartbeatPoolStub::class);
            /** @var KeepaliveConnectionStub $connection */
            $connection = $pool->borrow();
            $connection->heartbeatFailure = new RuntimeException('heartbeat fallback failed');
            $connection->reconnect();

            Coroutine::sleep(0.01);

            $this->assertFalse($connection->check());
            $this->assertSame(1, $connection->closeCount);
            $contents = file_get_contents($errorLog);
            $this->assertIsString($contents);
            $this->assertStringContainsString('heartbeat fallback failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            if ($previousLogErrors !== false) {
                ini_set('log_errors', $previousLogErrors);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testConnectionCloseProtocolRunsOnPoolFlush(): void
    {
        $container = $this->getContainer();
        $pool = $container->make(HeartbeatPoolStub::class);
        /** @var KeepaliveConnectionStub $connection */
        $connection = $pool->borrow();
        $connection->reconnect();
        $connection->release();

        $connection = $pool->borrow();
        $connection->reconnect();
        $connection->release();

        $pool->trimExcessIdle();

        $this->assertSame('close protocol', CoroutineContext::get('test.pool.heartbeat_connection')['close']);
    }

    #[DataProvider('heartbeatModes')]
    public function testCanceledWaiterPreservesTheActiveSocket(?float $heartbeatInterval): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', ['heartbeat_interval' => $heartbeatInterval]);
        $connection = $pool->borrow();
        $socket = new stdClass;
        $connection->setActiveConnection($socket);
        $release = new Channel(1);
        $completed = new Channel(1);
        $failure = null;

        Coroutine::create(function () use ($connection, $release, $completed): void {
            try {
                $connection->call(fn () => $release->pop(1.0));
            } finally {
                $completed->push(true);
            }
        });

        try {
            $waiter = EngineCoroutine::create(function () use ($connection, &$failure): void {
                try {
                    $connection->call(static fn () => null);
                } catch (Throwable $exception) {
                    $failure = $exception;
                }
            });

            $this->assertTrue(EngineCoroutine::cancelById($waiter->getId()));
            $this->assertInstanceOf(CanceledException::class, $failure);
            $this->assertTrue($connection->isConnected());
        } finally {
            $release->push(true);
            $completed->pop(1.0);
            $this->assertSame($socket, $connection->call(static fn ($socket) => $socket));
            $connection->discard();
        }
    }

    #[DataProvider('heartbeatModes')]
    public function testCloseTimeoutClearsStateAndStopsHeartbeat(?float $heartbeatInterval): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', [
            'heartbeat_interval' => $heartbeatInterval,
            'wait_timeout' => 0.001,
        ]);
        $connection = $pool->borrow();
        $connection->setActiveConnection(new stdClass);
        $release = new Channel(1);
        $completed = new Channel(1);

        Coroutine::create(function () use ($connection, $release, $completed): void {
            try {
                $connection->call(fn () => $release->pop(1.0));
            } finally {
                $completed->push(true);
            }
        });

        try {
            try {
                $connection->close();
                $this->fail('Expected close to time out behind the active holder.');
            } catch (SocketPopException) {
                $this->assertFalse($connection->isConnected());
                $this->assertSame([], (new ClassInvoker($connection->timer))->coroutines);
            }
        } finally {
            $release->push(true);
            $completed->pop(1.0);
            $connection->discard();
            $connection->timer->clearAll();
        }
    }

    public function testSupersededHolderCannotRequeueOrRefreshTheReplacement(): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', ['wait_timeout' => 0.001]);
        $connection = $pool->borrow();
        $connection->createCallback = static fn () => new stdClass;
        $oldRelease = new Channel(1);
        $newRelease = new Channel(1);
        $oldCompleted = new Channel(1);
        $newCompleted = new Channel(1);
        $replacementStarted = false;

        Coroutine::create(function () use ($connection, $oldRelease, $oldCompleted): void {
            try {
                $connection->call(fn () => $oldRelease->pop(1.0));
            } finally {
                $oldCompleted->push(true);
            }
        });

        try {
            try {
                $connection->close();
                $this->fail('Expected the held socket to prevent protocol close.');
            } catch (SocketPopException) {
            }

            $replacementStarted = true;
            Coroutine::create(function () use ($connection, $newRelease, $newCompleted): void {
                try {
                    $connection->call(fn () => $newRelease->pop(1.0));
                } finally {
                    $newCompleted->push(true);
                }
            });

            $state = new ClassInvoker($connection);
            $lastUseTime = $state->lastUseTime;
            $oldRelease->push(true);
            $this->assertTrue($oldCompleted->pop(1.0));

            $this->assertSame($lastUseTime, $state->lastUseTime);
            $this->assertSame(0, $state->channel->getLength());
        } finally {
            $oldRelease->close();
            $newRelease->close();

            if ($replacementStarted) {
                $newCompleted->pop(1.0);
            }

            $connection->discard();
        }
    }

    #[DataProvider('cleanupFailures')]
    public function testConcurrentReconnectKeepsOneSocketAndOneTimer(?Throwable $closeFailure): void
    {
        $container = new Container;
        $logger = m::mock(StdoutLoggerInterface::class);
        $container->instance(StdoutLoggerInterface::class, $logger);

        if ($closeFailure !== null && ! $closeFailure instanceof CanceledException) {
            $logger->shouldReceive('error')->once()->with(m::on(
                static fn (string $message): bool => str_contains($message, $closeFailure->getMessage()),
            ));
        } else {
            $logger->shouldNotReceive('error');
        }

        $pool = new HeartbeatPoolStub($container, 'test', ['heartbeat_interval' => 3600.0]);
        $connection = $pool->borrow();
        $firstReady = new Channel(1);
        $secondReady = new Channel(1);
        $completed = new Channel(2);
        $created = [];
        $closed = [];
        $connection->createCallback = function () use (&$created, $firstReady, $secondReady): object {
            $socket = new stdClass;
            $created[] = $socket;
            (count($created) === 1 ? $firstReady : $secondReady)->pop(1.0);

            return $socket;
        };
        $connection->closeCallback = function (object $socket) use (&$closed, &$created, $closeFailure): void {
            $closed[] = $socket;

            if ($socket === $created[0] && $closeFailure !== null) {
                throw $closeFailure;
            }
        };

        try {
            foreach ([0, 1] as $attempt) {
                Coroutine::create(function () use ($connection, $completed): void {
                    try {
                        $completed->push($connection->call(static fn ($socket) => $socket));
                    } catch (Throwable $exception) {
                        $completed->push($exception);
                    }
                });
            }

            $secondReady->push(true);
            $firstReady->push(true);

            $this->assertSame($created[1], $completed->pop(1.0));
            $this->assertSame(
                $closeFailure instanceof CanceledException ? $closeFailure : $created[1],
                $completed->pop(1.0),
            );
            $this->assertSame([$created[0]], $closed);
            $this->assertCount(1, (new ClassInvoker($connection->timer))->coroutines);
        } finally {
            $firstReady->close();
            $secondReady->close();
            $connection->discard();
            $connection->timer->clearAll();
        }
    }

    public static function cleanupFailures(): array
    {
        return [
            'successful cleanup' => [null],
            'ordinary cleanup failure' => [new RuntimeException('loser close failed')],
            'canceled cleanup' => [new CanceledException('loser close canceled')],
        ];
    }

    #[DataProvider('heartbeatModes')]
    public function testCanceledCloseClearsStateAndReconnectWakesOldWaiters(?float $heartbeatInterval): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', [
            'heartbeat_interval' => $heartbeatInterval,
            'wait_timeout' => 1.0,
        ]);
        $connection = $pool->borrow();
        $connection->createCallback = static fn () => new stdClass;
        $release = new Channel(1);
        $completed = new Channel(1);
        $closeFailure = null;
        $waiterFailure = null;

        Coroutine::create(function () use ($connection, $release, $completed): void {
            try {
                $connection->call(fn () => $release->pop(1.0));
            } finally {
                $completed->push(true);
            }
        });

        $oldChannel = (new ClassInvoker($connection))->channel;

        try {
            $closer = EngineCoroutine::create(function () use ($connection, &$closeFailure): void {
                try {
                    $connection->close();
                } catch (Throwable $exception) {
                    $closeFailure = $exception;
                }
            });
            EngineCoroutine::create(function () use ($connection, &$waiterFailure): void {
                try {
                    $connection->call(static fn () => null);
                } catch (Throwable $exception) {
                    $waiterFailure = $exception;
                }
            });

            $this->assertTrue(EngineCoroutine::cancelById($closer->getId()));
            $this->assertInstanceOf(CanceledException::class, $closeFailure);
            $this->assertFalse($connection->isConnected());
            $this->assertSame([], (new ClassInvoker($connection->timer))->coroutines);
            $this->assertNull($waiterFailure);

            $connection->call(static fn () => null);

            $this->assertInstanceOf(SocketPopException::class, $waiterFailure);
            $this->assertTrue($oldChannel->isClosing());
            $this->assertFalse($oldChannel->isCanceled());
            $this->assertTrue($connection->isConnected());
        } finally {
            $oldChannel->close();
            $release->push(true);
            $completed->pop(1.0);
            $connection->discard();
            $connection->timer->clearAll();
        }
    }

    public static function heartbeatModes(): array
    {
        return [
            'disabled' => [null],
            'enabled' => [3600.0],
        ];
    }

    public function testLateCloseDoesNotClearAReplacement(): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', [
            'heartbeat_interval' => 3600.0,
            'wait_timeout' => 0.001,
        ]);
        $connection = $pool->borrow();
        $connection->createCallback = static fn () => new stdClass;
        $original = $connection->call(static fn ($socket) => $socket);
        $release = new Channel(1);
        $completed = new Channel(1);
        $connection->closeCallback = function (object $socket) use ($original, $release): void {
            if ($socket === $original) {
                $release->pop(1.0);
            }
        };

        Coroutine::create(function () use ($connection, $completed): void {
            try {
                $completed->push($connection->close());
            } catch (Throwable $exception) {
                $completed->push($exception);
            }
        });

        try {
            try {
                $connection->close();
                $this->fail('Expected close to time out behind the first close.');
            } catch (SocketPopException) {
            }

            $replacement = $connection->call(static fn ($socket) => $socket);
            $timerId = (new ClassInvoker($connection))->timerId;
            $release->push(true);

            $this->assertTrue($completed->pop(1.0));
            $this->assertTrue($connection->isConnected());
            $this->assertSame($timerId, (new ClassInvoker($connection))->timerId);
            $this->assertCount(1, (new ClassInvoker($connection->timer))->coroutines);
            $this->assertSame($replacement, $connection->call(static fn ($socket) => $socket));
        } finally {
            $release->close();
            $connection->discard();
            $connection->timer->clearAll();
        }
    }

    public function testCloseDuringTimerCreationDoesNotRetainTheTimer(): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', ['heartbeat_interval' => 3600.0]);
        $connection = $pool->borrow();
        $connection->setActiveConnection(new stdClass);
        $closed = false;
        Coroutine::afterCreated(function () use ($connection, &$closed): void {
            if (! $closed) {
                $closed = true;
                $connection->close();
            }
        });

        try {
            $connection->reconnect();

            $this->assertTrue($closed);
            $this->assertFalse($connection->isConnected());
            $this->assertNull((new ClassInvoker($connection))->timerId);
            $this->assertSame([], (new ClassInvoker($connection->timer))->coroutines);
        } finally {
            $connection->discard();
            $connection->timer->clearAll();
        }
    }

    public function testReconnectPublishesIfAnEarlierWinnerHasAlreadyClosed(): void
    {
        $pool = new HeartbeatPoolStub(new Container, 'test', ['heartbeat_interval' => 3600.0]);
        $connection = $pool->borrow();
        $ready = new Channel(1);
        $completed = new Channel(1);
        $created = [];
        $connection->createCallback = function () use (&$created, $ready): object {
            $socket = new stdClass;
            $created[] = $socket;

            if (count($created) === 1) {
                $ready->pop(1.0);
            }

            return $socket;
        };

        Coroutine::create(function () use ($connection, $completed): void {
            try {
                $completed->push($connection->call(static fn ($socket) => $socket));
            } catch (Throwable $exception) {
                $completed->push($exception);
            }
        });

        try {
            $winner = $connection->call(static fn ($socket) => $socket);
            $previousChannel = (new ClassInvoker($connection))->channel;
            $this->assertSame($created[1], $winner);
            $connection->close();
            $ready->push(true);

            $this->assertSame($created[0], $completed->pop(1.0));
            $this->assertTrue($previousChannel->isClosing());
            $this->assertTrue($connection->isConnected());
            $this->assertCount(1, (new ClassInvoker($connection->timer))->coroutines);
        } finally {
            $ready->close();
            $connection->discard();
            $connection->timer->clearAll();
        }
    }

    public function testHeartbeatCancellationRemainsPrimaryWhenProtocolCloseFails(): void
    {
        $container = new Container;
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('error');
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = new HeartbeatPoolStub($container, 'test', ['heartbeat_interval' => 3600.0]);
        $connection = $pool->borrow();
        $connection->setActiveConnection(new stdClass);
        $callback = null;
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')->once()->andReturnUsing(
            function (float $interval, callable $heartbeat) use (&$callback): int {
                $callback = $heartbeat;

                return 1;
            },
        );
        $timer->shouldReceive('clear')->once()->with(1);
        $connection->timer = $timer;
        $cancellation = new CanceledException('heartbeat canceled');
        $connection->heartbeatFailure = $cancellation;
        $connection->closeCallback = static fn () => throw new RuntimeException('protocol close failed');

        try {
            $connection->reconnect();

            try {
                $callback();
                $this->fail('Expected the heartbeat cancellation.');
            } catch (CanceledException $exception) {
                $this->assertSame($cancellation, $exception);
            }

            $this->assertFalse($connection->isConnected());
            $this->assertSame(1, $connection->closeCount);
            $this->assertNull((new ClassInvoker($connection))->timerId);
        } finally {
            $connection->discard();
        }
    }

    #[DataProvider('heartbeatFailures')]
    public function testLateHeartbeatFailureDoesNotCloseTheReplacement(Throwable $failure): void
    {
        $container = new Container;
        $logger = m::mock(StdoutLoggerInterface::class);
        $container->instance(StdoutLoggerInterface::class, $logger);

        if ($failure instanceof CanceledException) {
            $logger->shouldNotReceive('error');
        } else {
            $logger->shouldReceive('error')->once()->with(m::on(
                static fn (string $message): bool => str_contains($message, $failure->getMessage()),
            ));
        }

        $pool = new HeartbeatPoolStub($container, 'test', [
            'heartbeat_interval' => 3600.0,
            'wait_timeout' => 0.001,
        ]);
        $connection = $pool->borrow();
        $connection->createCallback = static fn () => new stdClass;
        $callbacks = [];
        $cleared = [];
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')->twice()->andReturnUsing(
            function (float $interval, callable $heartbeat) use (&$callbacks): int {
                $callbacks[] = $heartbeat;

                return count($callbacks);
            },
        );
        $timer->shouldReceive('clear')->andReturnUsing(function (int $id) use (&$cleared): void {
            $cleared[] = $id;
        });
        $connection->timer = $timer;
        $release = new Channel(1);
        $completed = new Channel(1);
        $connection->heartbeatCallback = function () use ($connection, $release, $failure): void {
            $connection->call(function () use ($release, $failure): void {
                $release->pop(1.0);

                throw $failure;
            }, false);
        };
        $connection->reconnect();

        Coroutine::create(function () use (&$callbacks, $completed): void {
            try {
                $callbacks[0]();
                $completed->push(true);
            } catch (Throwable $exception) {
                $completed->push($exception);
            }
        });

        try {
            try {
                $connection->close();
                $this->fail('Expected protocol work to hold the old socket.');
            } catch (SocketPopException) {
            }

            $replacement = $connection->call(static fn ($socket) => $socket);
            $release->push(true);

            $this->assertSame($failure instanceof CanceledException ? $failure : true, $completed->pop(1.0));
            $this->assertSame([1], $cleared);
            $this->assertTrue($connection->isConnected());
            $this->assertSame(2, (new ClassInvoker($connection))->timerId);
            $this->assertSame($replacement, $connection->call(static fn ($socket) => $socket));
        } finally {
            $release->close();
            $connection->discard();
        }
    }

    public static function heartbeatFailures(): array
    {
        return [
            'ordinary failure' => [new RuntimeException('late heartbeat failed')],
            'cancellation' => [new CanceledException('late heartbeat canceled')],
        ];
    }

    protected function getContainer(array $poolConfig = []): ContainerContract
    {
        $container = m::mock(Container::class);
        Container::setInstance($container);

        $container->shouldReceive('make')->with(HeartbeatPoolStub::class)->andReturnUsing(function () use ($container, $poolConfig) {
            return new HeartbeatPoolStub($container, 'test', $poolConfig);
        });

        return $container;
    }
}
