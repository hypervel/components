<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Closure;
use Hypervel\ConnectionPool\Connection as PoolConnection;
use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\ConnectionPool\Exceptions\PoolClosedException;
use Hypervel\ConnectionPool\Exceptions\PoolExhaustedException;
use Hypervel\ConnectionPool\IdleConnectionMonitor;
use Hypervel\Container\Container;
use Hypervel\Contracts\ConnectionPool\Connection;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\ConnectionPool\UsageTracker;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\PoolChannel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ConnectionPoolTest extends TestCase
{
    public function testUnknownPoolOptionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown connection pool option(s) [max_conections]');

        $this->createPool(['max_conections' => 10]);
    }

    public function testTrimExcessIdleClosesConnectionsUntilManagedCountReachesMinimum(): void
    {
        $connections = [];
        $pool = $this->createPool(
            ['min_retained_connections' => 1, 'max_connections' => 3],
            function () use (&$connections): Connection {
                return $connections[] = new PoolConnectionStub;
            }
        );

        $borrowed = [$pool->borrow(), $pool->borrow(), $pool->borrow()];

        foreach ($borrowed as $connection) {
            $pool->release($connection);
        }

        $pool->trimExcessIdle();

        $this->assertSame(1, $pool->getIdleCount());
        $this->assertSame(1, $pool->getManagedCount());
        $this->assertSame(2, array_sum(array_column($connections, 'closeCount')));
    }

    public function testCloseDrainsIdleConnectionsAndIsIdempotent(): void
    {
        $connections = [];
        $pool = $this->createPool(
            ['max_connections' => 2],
            function () use (&$connections): Connection {
                return $connections[] = new PoolConnectionStub;
            }
        );

        $first = $pool->borrow();
        $second = $pool->borrow();
        $pool->release($first);
        $pool->release($second);

        $pool->close();
        $pool->close();

        $this->assertTrue($pool->isClosed());
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(2, array_sum(array_column($connections, 'closeCount')));
    }

    public function testSnapshotsDistinguishCreationBorrowingAndCleanup(): void
    {
        $duringCreation = null;
        $duringClose = null;
        $pool = null;
        $pool = $this->createPool([], function () use (&$pool, &$duringCreation, &$duringClose): Connection {
            $duringCreation = $pool->getStats();

            return new PoolConnectionStub(closeCallback: function () use (&$pool, &$duringClose): bool {
                $duringClose = $pool->getStats();

                return true;
            });
        });

        try {
            $connection = $pool->borrow();

            $this->assertSame([
                'managed' => 0, 'borrowed' => 0, 'idle' => 0, 'waiting' => 0, 'closed' => false,
            ], $duringCreation);
            $this->assertSame([
                'managed' => 1, 'borrowed' => 1, 'idle' => 0, 'waiting' => 0, 'closed' => false,
            ], $pool->getStats());

            $pool->release($connection);

            $this->assertSame([
                'managed' => 1, 'borrowed' => 0, 'idle' => 1, 'waiting' => 0, 'closed' => false,
            ], $pool->getStats());
        } finally {
            $pool->close();
        }

        $this->assertSame([
            'managed' => 1, 'borrowed' => 0, 'idle' => 0, 'waiting' => 0, 'closed' => true,
        ], $duringClose);
        $this->assertSame([
            'managed' => 0, 'borrowed' => 0, 'idle' => 0, 'waiting' => 0, 'closed' => true,
        ], $pool->getStats());
    }

    public function testCloseDrainsEveryIdleConnectionBeforeRethrowingCancellation(): void
    {
        $cancellation = new CanceledException('close canceled');
        $connections = [
            new PoolConnectionStub(closeCallback: static fn (): bool => throw $cancellation),
            new PoolConnectionStub,
        ];
        $pool = $this->createPool(
            ['max_connections' => 2],
            static function () use (&$connections): Connection {
                return array_shift($connections);
            },
        );
        $first = $pool->borrow();
        $second = $pool->borrow();
        $pool->release($first);
        $pool->release($second);

        try {
            $pool->close();
            $this->fail('Closing an idle connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $first->closeCount);
        $this->assertSame(1, $second->closeCount);
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testFirstBorrowStartsTheMonitorAndCloseClearsIt(): void
    {
        $pool = new MonitoredPool($this->createContainer(), 'test', ['idle_check_interval' => 0.001]);
        $pool->start();
        $this->assertSame(0, Timer::stats()['num']);

        try {
            $pool->release($pool->borrow());
            Coroutine::sleep(0.005);
            $this->assertGreaterThan(0, $pool->idleConnectionChecks);

            $pool->close();
            $idleConnectionChecks = $pool->idleConnectionChecks;
            Coroutine::sleep(0.005);

            $this->assertSame(0, Timer::stats()['num']);
            $this->assertSame($idleConnectionChecks, $pool->idleConnectionChecks);
        } finally {
            $pool->close();
        }
    }

    public function testWarmPoolDoesNotScheduleMaintenanceUntilStarted(): void
    {
        $pool = $this->createPool(['idle_check_interval' => 60.0]);
        $timerCount = Timer::stats()['num'];

        try {
            $pool->release($pool->borrow());
            $this->assertSame($timerCount, Timer::stats()['num']);

            $pool->start();
            $pool->start();
            $this->assertSame($timerCount + 1, Timer::stats()['num']);

            $pool->close();
            $pool->start();
            $this->assertSame($timerCount, Timer::stats()['num']);
        } finally {
            $pool->close();
        }
    }

    public function testCloseContinuesAfterMonitorCleanupFails(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(m::on(static fn (string $message): bool => str_contains($message, 'monitor cleanup failed')));
        $container = $this->createContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = new CallbackPool($container, 'test');
        $channel = new InspectablePoolChannel(1);
        $pool->replaceChannel($channel);
        $connection = $pool->borrow();
        $pool->release($connection);
        $timer = m::mock(Timer::class);
        $timer->expects('tick')->with(1.0, m::type('callable'))->andReturn(1);
        $timer->expects('clear')->with(1)->andThrow(new RuntimeException('monitor cleanup failed'));
        $monitor = new IdleConnectionMonitor($pool, 1.0, $timer);
        $monitor->start();
        (new ReflectionProperty(ConnectionPool::class, 'idleMonitor'))->setValue($pool, $monitor);

        $pool->close();

        $this->assertTrue($channel->isClosedForTest());
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testBorrowFromClosedPoolThrows(): void
    {
        $pool = $this->createPool();
        $pool->close();

        $this->expectException(PoolClosedException::class);
        $this->expectExceptionMessage('Cannot borrow from a closed connection pool.');

        $pool->borrow();
    }

    public function testConnectionReleasedAfterCloseIsDestroyed(): void
    {
        $connection = new PoolConnectionStub;
        $pool = $this->createPool([], static fn (): Connection => $connection);
        $borrowed = $pool->borrow();

        $pool->close();
        $pool->release($borrowed);

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testConnectionReleaseAfterCloseRepairsCapacityBeforeRethrowingCancellation(): void
    {
        $container = $this->createContainer();
        $cancellation = new CanceledException('close canceled');
        $pool = null;
        $pool = new CallbackPool(
            $container,
            'test',
            connectionFactory: static function () use (&$pool, $cancellation, $container): Connection {
                return new ReleasingPoolConnectionStub(
                    $container,
                    $pool,
                    static fn (): bool => throw $cancellation,
                );
            },
        );
        $connection = $pool->borrow();
        $pool->close();

        try {
            $connection->release();
            $this->fail('Releasing the connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getBorrowedCount());
    }

    public function testCloseWakesEveryParkedBorrower(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.2]);
        $borrowed = $pool->borrow();
        $messages = [];

        foreach ([0, 1] as $index) {
            Coroutine::create(function () use ($pool, &$messages, $index): void {
                try {
                    $pool->borrow();
                } catch (PoolClosedException $exception) {
                    $messages[$index] = $exception->getMessage();
                }
            });
        }

        usleep(5_000);
        $this->assertSame(2, $pool->getWaitingCount());
        $this->assertSame(2, $pool->getStats()['waiting']);
        $pool->close();
        usleep(5_000);
        $this->assertSame(0, $pool->getWaitingCount());
        $pool->release($borrowed);

        ksort($messages);
        $this->assertSame([
            'Cannot borrow from a closed connection pool.',
            'Cannot borrow from a closed connection pool.',
        ], $messages);
    }

    public function testCloseDuringSuspendedFactoryDestroysOrphan(): void
    {
        $connection = new PoolConnectionStub;
        $pool = $this->createPool([], function () use ($connection): Connection {
            usleep(10_000);

            return $connection;
        });
        $message = null;

        Coroutine::create(function () use ($pool, &$message): void {
            try {
                $pool->borrow();
            } catch (PoolClosedException $exception) {
                $message = $exception->getMessage();
            }
        });

        usleep(2_000);
        $pool->close();
        usleep(15_000);

        $this->assertSame('Cannot borrow from a closed connection pool.', $message);
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testHugeFiniteWaitTimeoutSaturatesInsteadOfTimingOutImmediately(): void
    {
        $pool = $this->createPool([
            'max_connections' => 1,
            'wait_timeout' => PHP_INT_MAX,
        ]);
        $borrowed = $pool->borrow();
        $result = null;
        $failure = null;

        $this->assertSame(PHP_INT_MAX, $pool->nanosecondsForTest((float) PHP_INT_MAX));
        $this->assertSame(PHP_INT_MAX, $pool->deadlineForTest((float) PHP_INT_MAX));

        Coroutine::create(function () use ($pool, &$result, &$failure): void {
            try {
                $result = $pool->borrow();
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
        });

        usleep(2_000);
        $pool->release($borrowed);
        usleep(2_000);

        $this->assertNull($failure);
        $this->assertInstanceOf(Connection::class, $result);
        $pool->release($result);
        $pool->close();
    }

    public function testForeignAndDoubleReleasesAreRejected(): void
    {
        $pool = $this->createPool();
        $connection = $pool->borrow();
        $pool->release($connection);

        try {
            $pool->release($connection);
            $this->fail('A double release must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not checked out', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not manage');

        $pool->release(new PoolConnectionStub);
    }

    public function testDiscardDestroysBorrowedConnectionAndRestoresCapacity(): void
    {
        $connections = [];
        $pool = $this->createPool(
            ['max_connections' => 1],
            function () use (&$connections): Connection {
                return $connections[] = new PoolConnectionStub;
            },
        );
        $connection = $pool->borrow();

        $pool->discard($connection);

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertNotSame($connection, $replacement = $pool->borrow());
        $this->assertSame(1, $pool->getManagedCount());
        $pool->release($replacement);
    }

    public function testDiscardRepairsCapacityBeforeRethrowingCancellation(): void
    {
        $cancellation = new CanceledException('close canceled');
        $connections = [
            new PoolConnectionStub(closeCallback: static fn (): bool => throw $cancellation),
            new PoolConnectionStub,
        ];
        $pool = $this->createPool(
            ['max_connections' => 1],
            static function () use (&$connections): Connection {
                return array_shift($connections);
            },
        );
        $connection = $pool->borrow();

        try {
            $pool->discard($connection);
            $this->fail('Discarding the connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertNotSame($connection, $replacement = $pool->borrow());
        $pool->release($replacement);
    }

    public function testForeignIdleAndAlreadyDiscardedConnectionsAreRejected(): void
    {
        $pool = $this->createPool();

        try {
            $pool->discard(new PoolConnectionStub);
            $this->fail('Discarding a foreign connection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not manage', $exception->getMessage());
        }

        $idle = $pool->borrow();
        $pool->release($idle);

        try {
            $pool->discard($idle);
            $this->fail('Discarding an idle connection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not checked out', $exception->getMessage());
        }

        $discarded = $pool->borrow();
        $pool->discard($discarded);

        try {
            $pool->discard($discarded);
            $this->fail('Discarding a destroyed connection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not manage', $exception->getMessage());
        }
    }

    public function testDuplicateFactoryConnectionIsRejected(): void
    {
        $connection = new PoolConnectionStub;
        $pool = $this->createPool(
            ['max_connections' => 2],
            static fn (): Connection => $connection
        );
        $pool->borrow();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already manages');

        $pool->borrow();
    }

    public function testYieldingFactoriesNeverExceedCapacity(): void
    {
        $factoriesRunning = 0;
        $maximumFactoriesRunning = 0;
        $pool = $this->createPool(
            ['max_connections' => 2, 'wait_timeout' => 0.5],
            function () use (&$factoriesRunning, &$maximumFactoriesRunning): Connection {
                ++$factoriesRunning;
                $maximumFactoriesRunning = max($maximumFactoriesRunning, $factoriesRunning);
                usleep(5_000);
                --$factoriesRunning;

                return new PoolConnectionStub;
            }
        );

        $results = parallel(array_fill(0, 8, function () use ($pool): bool {
            $connection = $pool->borrow();
            usleep(2_000);
            $pool->release($connection);

            return true;
        }));

        $this->assertSame(array_fill(0, 8, true), $results);
        $this->assertSame(2, $maximumFactoriesRunning);
        $this->assertSame(2, $pool->getManagedCount());
    }

    public function testCreationFailureWakesAnotherBorrower(): void
    {
        $factoryCalls = 0;
        $pool = $this->createPool(
            ['max_connections' => 1, 'wait_timeout' => 0.2],
            function () use (&$factoryCalls): Connection {
                ++$factoryCalls;

                if ($factoryCalls === 1) {
                    usleep(5_000);
                    throw new RuntimeException('factory failed');
                }

                return new PoolConnectionStub;
            }
        );

        $results = parallel([
            function () use ($pool): string {
                try {
                    $pool->borrow();
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                }

                return 'unexpected';
            },
            function () use ($pool): string {
                $connection = $pool->borrow();
                $pool->release($connection);

                return 'borrowed';
            },
        ]);

        $this->assertSame(['factory failed', 'borrowed'], $results);
    }

    public function testExhaustedPoolTimesOut(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.001]);
        $pool->borrow();

        $this->expectException(PoolExhaustedException::class);
        $this->expectExceptionMessage(
            'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
        );

        $pool->borrow();
    }

    #[DataProvider('checkoutCancellationModes')]
    public function testCanceledCheckoutDoesNotCreateAPhantomBorrow(bool $throwException): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 1.0]);
        $channel = new InspectablePoolChannel(1);
        $pool->replaceChannel($channel);
        $borrowed = $pool->borrow();
        $cancellation = null;
        $unexpectedConnection = null;

        $coroutine = EngineCoroutine::create(function () use (
            $pool,
            &$cancellation,
            &$unexpectedConnection,
        ): void {
            try {
                $unexpectedConnection = $pool->borrow();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        $this->assertSame(1, $channel->getWaitersForTest());
        $this->assertTrue(EngineCoroutine::cancelById($coroutine->getId(), $throwException));
        $this->assertInstanceOf(CanceledException::class, $cancellation);

        if ($throwException) {
            $this->assertNotSame('The pool wait was canceled.', $cancellation->getMessage());
        } else {
            $this->assertSame('The pool wait was canceled.', $cancellation->getMessage());
        }

        $this->assertNull($unexpectedConnection);
        $this->assertSame(1, $pool->getManagedCount());
        $this->assertSame(1, $pool->getBorrowedCount());
        $this->assertSame(0, $channel->getWaitersForTest());

        $pool->release($borrowed);
        $replacement = $pool->borrow();
        $pool->release($replacement);
    }

    public static function checkoutCancellationModes(): array
    {
        return [
            'throwing cancellation' => [true],
            'non-throwing cancellation' => [false],
        ];
    }

    public function testCheckoutPerformsOneFinalPassAfterADeadlineRelease(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.001]);
        $borrowed = $pool->borrow();
        $channel = new DeadlinePoolChannel(function () use ($borrowed, $pool): void {
            $pool->release($borrowed);
        });
        $pool->replaceChannel($channel);

        $this->assertSame($borrowed, $returned = $pool->borrow());
        $this->assertSame(1, $channel->waitCount);

        $pool->release($returned);
    }

    public function testCheckoutPerformsOneFinalPassAfterADeadlineDiscard(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.001]);
        $borrowed = $pool->borrow();
        $channel = new DeadlinePoolChannel(function () use ($borrowed, $pool): void {
            $pool->discard($borrowed);
        });
        $pool->replaceChannel($channel);

        $this->assertNotSame($borrowed, $replacement = $pool->borrow());
        $this->assertSame(1, $channel->waitCount);

        $pool->release($replacement);
    }

    public function testUnhealthyIdleConnectionIsDestroyed(): void
    {
        $first = new PoolConnectionStub(checkCallback: static fn (): bool => false);
        $second = new PoolConnectionStub;
        $connections = [$first, $second];
        $pool = $this->createPool(
            ['max_connections' => 1],
            static function () use (&$connections): Connection {
                return array_shift($connections);
            }
        );
        $connection = $pool->borrow();
        $pool->release($connection);

        $pool->checkIdleConnection();
        $replacement = $pool->borrow();

        $this->assertSame(1, $first->closeCount);
        $this->assertSame($second, $replacement);
    }

    public function testThrowingIdleCheckIsReportedDestroyedAndFreesCapacity(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(m::on(static fn (string $message): bool => str_contains($message, 'check failed')));
        $container = $this->createContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);

        $first = new PoolConnectionStub(
            checkCallback: static fn (): bool => throw new RuntimeException('check failed')
        );
        $second = new PoolConnectionStub;
        $connections = [$first, $second];
        $pool = new CallbackPool(
            $container,
            'test',
            ['max_connections' => 1],
            static function () use (&$connections): Connection {
                return array_shift($connections);
            }
        );
        $connection = $pool->borrow();
        $pool->release($connection);

        $pool->checkIdleConnection();
        $replacement = $pool->borrow();

        $this->assertSame(1, $first->closeCount);
        $this->assertSame($second, $replacement);
        $this->assertSame(1, $pool->getManagedCount());
    }

    public function testHealthyIdleCheckRequeuesConnection(): void
    {
        $connection = new PoolConnectionStub;
        $pool = $this->createPool([], static fn (): Connection => $connection);
        $pool->release($pool->borrow());

        $pool->checkIdleConnection();

        $this->assertSame(1, $connection->checkCount);
        $this->assertSame(0, $connection->closeCount);
        $this->assertSame($connection, $pool->borrow());
    }

    #[DataProvider('idleCheckCleanupFailures')]
    public function testCanceledIdleCheckDisposesOnceAndPreservesCancellation(?string $cleanupFailure): void
    {
        $cancellation = new CanceledException('idle check canceled');
        $secondary = match ($cleanupFailure) {
            'canceled' => new CanceledException('close canceled'),
            'failed' => new RuntimeException('close failed'),
            default => null,
        };
        $container = $this->createContainer();
        $logger = m::mock(StdoutLoggerInterface::class);

        if ($cleanupFailure === 'failed') {
            $logger->shouldReceive('error')->once()->with((string) $secondary);
        } else {
            $logger->shouldNotReceive('error');
        }

        $container->instance(StdoutLoggerInterface::class, $logger);
        $connection = new PoolConnectionStub(
            checkCallback: static fn (): bool => throw $cancellation,
            closeCallback: static fn (): bool => $secondary === null ? true : throw $secondary,
        );
        $pool = new CallbackPool($container, 'test', connectionFactory: static fn () => $connection);
        $pool->release($pool->borrow());
        $caught = null;

        try {
            $pool->checkIdleConnection();
        } catch (CanceledException $exception) {
            $caught = $exception;
        } finally {
            $pool->close();
        }

        $this->assertSame($cancellation, $caught);
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getIdleCount());
    }

    public static function idleCheckCleanupFailures(): array
    {
        return [[null], ['canceled'], ['failed']];
    }

    public function testIdleDisposalCancellationPropagatesWithoutSecondDestruction(): void
    {
        $cancellation = new CanceledException('idle disposal canceled');
        $connection = new PoolConnectionStub(
            checkCallback: static fn (): bool => false,
            closeCallback: static fn (): bool => throw $cancellation,
        );
        $pool = $this->createPool(factory: static fn () => $connection);
        $pool->release($pool->borrow());
        $caught = null;

        try {
            $pool->checkIdleConnection();
        } catch (CanceledException $exception) {
            $caught = $exception;
        } finally {
            $pool->close();
        }

        $this->assertSame($cancellation, $caught);
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testHealthyIdleConnectionIsDisposedWhenPoolClosesDuringCheck(): void
    {
        $pool = null;
        $connection = new PoolConnectionStub(checkCallback: static function () use (&$pool): bool {
            $pool->close();

            return true;
        });
        $pool = $this->createPool(factory: static fn () => $connection);
        $pool->release($pool->borrow());

        $pool->checkIdleConnection();

        $this->assertTrue($pool->isClosed());
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getIdleCount());
    }

    public function testUsageTrackerFailureIsReportedWithoutLosingBorrow(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(m::on(static fn (string $message): bool => str_contains($message, 'recording failed')));
        $container = $this->createContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = new CallbackPool($container, 'test');
        $tracker = m::mock(UsageTracker::class);
        $tracker->shouldReceive('recordBorrow')->once()->andThrow(new RuntimeException('recording failed'));
        $pool->useUsageTracker($tracker);

        $connection = $pool->borrow();

        $this->assertInstanceOf(Connection::class, $connection);
        $pool->release($connection);
    }

    public function testUsageMaintenanceCancellationDiscardsTheUnreturnedBorrow(): void
    {
        $cancellation = new CanceledException('maintenance canceled');
        $connections = [
            new PoolConnectionStub,
            new PoolConnectionStub(closeCallback: static fn (): bool => throw $cancellation),
            new PoolConnectionStub,
        ];
        $pool = $this->createPool(
            ['min_retained_connections' => 0, 'max_connections' => 2],
            static function () use (&$connections): Connection {
                return array_shift($connections);
            },
        );
        $first = $pool->borrow();
        $second = $pool->borrow();
        $pool->release($first);
        $pool->release($second);
        $pool->useUsageTracker(new class implements UsageTracker {
            public function recordBorrow(): void
            {
            }

            public function shouldTrimExcessIdle(): bool
            {
                return true;
            }
        });

        try {
            $pool->borrow();
            $this->fail('Usage maintenance was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $first->closeCount);
        $this->assertSame(1, $second->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getBorrowedCount());

        $replacement = $pool->borrow();
        $pool->release($replacement);
    }

    public function testUsageTrackerFactoryRunsOnceAfterSubclassConstruction(): void
    {
        $tracker = m::mock(UsageTracker::class);
        $tracker->expects('recordBorrow')->twice();
        $tracker->expects('shouldTrimExcessIdle')->twice()->andReturnFalse();
        $pool = new UsageTrackerPool($this->createContainer(), static fn () => $tracker);

        $this->assertSame(0, $pool->factoryCalls);

        try {
            $pool->release($pool->borrow());
            $pool->release($pool->borrow());

            $this->assertSame(1, $pool->factoryCalls);
        } finally {
            $pool->close();
        }
    }

    public function testNullUsagePolicyIsInitializedOnlyOnce(): void
    {
        $pool = new UsageTrackerPool($this->createContainer(), static fn () => null);

        try {
            $pool->release($pool->borrow());
            $pool->release($pool->borrow());

            $this->assertSame(1, $pool->factoryCalls);
            $this->assertSame(0, Timer::stats()['num']);
        } finally {
            $pool->close();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testUsageFactoryFailureRetainsOwnershipAndAllowsRetry(bool $cancel): void
    {
        $failure = $cancel
            ? new CanceledException('factory canceled')
            : new RuntimeException('factory failed');
        $attempts = 0;
        $pool = new UsageTrackerPool(
            $this->createContainer(),
            static function () use (&$attempts, $failure): ?UsageTracker {
                if (++$attempts === 1) {
                    throw $failure;
                }

                return null;
            },
        );
        $caught = null;

        try {
            try {
                $connection = $pool->borrow();
                $this->assertSame(1, $pool->getBorrowedCount());
                $pool->release($connection);
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($cancel ? $failure : null, $caught);
            $this->assertSame(0, $pool->getBorrowedCount());
            $this->assertSame($cancel ? 0 : 1, $pool->getManagedCount());

            $pool->release($pool->borrow());
            $pool->release($pool->borrow());
            $this->assertSame(2, $pool->factoryCalls);
        } finally {
            $pool->close();
        }
    }

    public function testFailedAcquisitionDoesNotInitializeMaintenance(): void
    {
        $failure = new RuntimeException('connection creation failed');
        $pool = new UsageTrackerPool(
            $this->createContainer(),
            static fn () => null,
            ['idle_check_interval' => 1.0],
            static fn () => throw $failure,
        );
        $caught = null;
        $pool->start();

        try {
            $pool->borrow();
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            $pool->close();
        }

        $this->assertSame($failure, $caught);
        $this->assertSame(0, $pool->factoryCalls);
        $this->assertSame(0, Timer::stats()['num']);
    }

    public function testCloseDuringMonitorStartupDoesNotPublishATimer(): void
    {
        $pool = $this->createPool(['idle_check_interval' => 60.0]);
        $pool->start();
        Coroutine::afterCreated(static fn () => $pool->close());

        $connection = $pool->borrow();

        try {
            $this->assertTrue($pool->isClosed());
            $this->assertSame(0, Timer::stats()['num']);
        } finally {
            $pool->release($connection);
        }

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testCloseDuringUsageTrimmingDoesNotStartTheMonitor(): void
    {
        $pool = new class($this->createContainer(), 'test', ['idle_check_interval' => 60.0]) extends CallbackPool {
            public function trimExcessIdle(): void
            {
                $this->close();
            }
        };
        $tracker = m::mock(UsageTracker::class);
        $tracker->expects('recordBorrow');
        $tracker->expects('shouldTrimExcessIdle')->andReturnTrue();
        $pool->useUsageTracker($tracker);
        $pool->start();

        $connection = $pool->borrow();
        $pool->release($connection);

        $this->assertTrue($pool->isClosed());
        $this->assertSame(0, Timer::stats()['num']);
        $this->assertSame(1, $connection->closeCount);
    }

    protected function createPool(array $config = [], ?Closure $factory = null): CallbackPool
    {
        return new CallbackPool($this->createContainer(), 'test', $config, $factory);
    }

    protected function createContainer(): Container
    {
        $container = new Container;
        Container::setInstance($container);

        return $container;
    }
}

class CallbackPool extends ConnectionPool
{
    protected Closure $connectionFactory;

    public function __construct(
        ContainerContract $container,
        string $name,
        array $config = [],
        ?Closure $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory
            ?? static fn (): Connection => new PoolConnectionStub;

        parent::__construct($container, $name, $config);
    }

    public function useUsageTracker(?UsageTracker $tracker): void
    {
        $this->usageTracker = $tracker;
        $this->usageTrackerInitialized = true;
    }

    public function nanosecondsForTest(float $seconds): int
    {
        return $this->nanoseconds($seconds);
    }

    public function deadlineForTest(float $seconds): int
    {
        return $this->deadline($seconds);
    }

    public function replaceChannel(PoolChannel $channel): void
    {
        $this->channel = $channel;
    }

    protected function createConnection(): Connection
    {
        return ($this->connectionFactory)();
    }
}

class MonitoredPool extends CallbackPool
{
    public int $idleConnectionChecks = 0;

    public function checkIdleConnection(): void
    {
        ++$this->idleConnectionChecks;
    }
}

class InspectablePoolChannel extends PoolChannel
{
    public function isClosedForTest(): bool
    {
        return $this->closed;
    }

    public function getWaitersForTest(): int
    {
        return $this->waiters;
    }
}

class UsageTrackerPool extends CallbackPool
{
    public int $factoryCalls = 0;

    protected Closure $trackerFactory;

    public function __construct(
        ContainerContract $container,
        Closure $trackerFactory,
        array $config = [],
        ?Closure $connectionFactory = null,
    ) {
        parent::__construct($container, 'test', $config, $connectionFactory);
        $this->trackerFactory = $trackerFactory;
    }

    protected function createUsageTracker(): ?UsageTracker
    {
        ++$this->factoryCalls;

        return ($this->trackerFactory)();
    }
}

class DeadlinePoolChannel extends PoolChannel
{
    public int $waitCount = 0;

    public function __construct(protected Closure $onWait)
    {
        parent::__construct(1);
    }

    public function wait(float $timeout): bool
    {
        ++$this->waitCount;
        ($this->onWait)();

        return false;
    }
}

class PoolConnectionStub implements Connection
{
    public int $checkCount = 0;

    public int $closeCount = 0;

    public function __construct(
        protected ?Closure $checkCallback = null,
        protected ?Closure $closeCallback = null,
    ) {
    }

    public function getConnection(): mixed
    {
        return $this;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function check(): bool
    {
        ++$this->checkCount;

        return $this->checkCallback ? ($this->checkCallback)() : true;
    }

    public function close(): bool
    {
        ++$this->closeCount;

        return $this->closeCallback ? ($this->closeCallback)() : true;
    }

    public function release(): void
    {
    }

    public function discard(): void
    {
    }
}

class ReleasingPoolConnectionStub extends PoolConnection
{
    public int $closeCount = 0;

    public function __construct(
        ContainerContract $container,
        ConnectionPoolContract $pool,
        protected Closure $closeCallback,
    ) {
        parent::__construct($container, $pool);
    }

    public function getActiveConnection(): mixed
    {
        return $this;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function close(): bool
    {
        ++$this->closeCount;

        return ($this->closeCallback)();
    }
}
