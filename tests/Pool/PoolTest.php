<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\FrequencyInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Pool\Channel as PoolChannel;
use Hypervel\Pool\ClearableFrequencyInterface;
use Hypervel\Pool\Connection as PoolConnection;
use Hypervel\Pool\LowFrequencyInterface;
use Hypervel\Pool\Pool;
use Hypervel\Tests\Pool\Fixtures\ConstantFrequencyStub;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;

class PoolTest extends TestCase
{
    public function testUnknownPoolOptionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown connection pool option(s) [max_conections]');

        $this->createPool(['max_conections' => 10]);
    }

    public function testFlushClosesIdleConnectionsUntilManagedCountReachesMinimum(): void
    {
        $connections = [];
        $pool = $this->createPool(
            ['min_connections' => 1, 'max_connections' => 3],
            function () use (&$connections): ConnectionInterface {
                return $connections[] = new PoolConnectionStub;
            }
        );

        $borrowed = [$pool->get(), $pool->get(), $pool->get()];

        foreach ($borrowed as $connection) {
            $pool->release($connection);
        }

        $pool->flush();

        $this->assertSame(1, $pool->getConnectionsInChannel());
        $this->assertSame(1, $pool->getCurrentConnections());
        $this->assertSame(2, array_sum(array_column($connections, 'closeCount')));
    }

    public function testCloseDrainsIdleConnectionsAndIsIdempotent(): void
    {
        $connections = [];
        $pool = $this->createPool(
            ['max_connections' => 2],
            function () use (&$connections): ConnectionInterface {
                return $connections[] = new PoolConnectionStub;
            }
        );

        $first = $pool->get();
        $second = $pool->get();
        $pool->release($first);
        $pool->release($second);

        $pool->close();
        $pool->close();

        $this->assertTrue($pool->isClosed());
        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertSame(0, $pool->getCurrentConnections());
        $this->assertSame(2, array_sum(array_column($connections, 'closeCount')));
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
            static function () use (&$connections): ConnectionInterface {
                return array_shift($connections);
            },
        );
        $first = $pool->get();
        $second = $pool->get();
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
        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertSame(0, $pool->getCurrentConnections());
    }

    public function testCloseClearsConstantFrequencyTimer(): void
    {
        $pool = new ConstantFrequencyPool($this->createContainer(), 'test');
        $pool->useFrequency(new ConstantFrequencyStub($pool));

        try {
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

    public function testCloseContinuesAfterFrequencyCleanupFails(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(m::on(static fn (string $message): bool => str_contains($message, 'frequency cleanup failed')));
        $container = $this->createContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = new CallbackPool($container, 'test');
        $channel = new InspectablePoolChannel(1);
        $pool->replaceChannel($channel);
        $connection = $pool->get();
        $pool->release($connection);
        $pool->useFrequency(new ThrowingClearableFrequency);

        $pool->close();

        $this->assertTrue($channel->isClosedForTest());
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertSame(0, $pool->getCurrentConnections());
    }

    public function testBorrowFromClosedPoolThrows(): void
    {
        $pool = $this->createPool();
        $pool->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot borrow from a closed connection pool.');

        $pool->get();
    }

    public function testConnectionReleasedAfterCloseIsDestroyed(): void
    {
        $connection = new PoolConnectionStub;
        $pool = $this->createPool([], static fn (): ConnectionInterface => $connection);
        $borrowed = $pool->get();

        $pool->close();
        $pool->release($borrowed);

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertSame(0, $pool->getCurrentConnections());
    }

    public function testConnectionReleaseAfterCloseRepairsCapacityBeforeRethrowingCancellation(): void
    {
        $container = $this->createContainer();
        $cancellation = new CanceledException('close canceled');
        $pool = null;
        $pool = new CallbackPool(
            $container,
            'test',
            connectionFactory: static function () use (&$pool, $cancellation, $container): ConnectionInterface {
                return new ReleasingPoolConnectionStub(
                    $container,
                    $pool,
                    static fn (): bool => throw $cancellation,
                );
            },
        );
        $connection = $pool->get();
        $pool->close();

        try {
            $connection->release();
            $this->fail('Releasing the connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getBorrowedConnectionsForTest());
    }

    public function testCloseWakesEveryParkedBorrower(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.2]);
        $borrowed = $pool->get();
        $messages = [];

        foreach ([0, 1] as $index) {
            Coroutine::create(function () use ($pool, &$messages, $index): void {
                try {
                    $pool->get();
                } catch (RuntimeException $exception) {
                    $messages[$index] = $exception->getMessage();
                }
            });
        }

        usleep(5_000);
        $this->assertSame(2, $pool->getWaiters());
        $pool->close();
        usleep(5_000);
        $this->assertSame(0, $pool->getWaiters());
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
        $pool = $this->createPool([], function () use ($connection): ConnectionInterface {
            usleep(10_000);

            return $connection;
        });
        $message = null;

        Coroutine::create(function () use ($pool, &$message): void {
            try {
                $pool->get();
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
            }
        });

        usleep(2_000);
        $pool->close();
        usleep(15_000);

        $this->assertSame('Cannot borrow from a closed connection pool.', $message);
        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getCurrentConnections());
    }

    public function testHugeFiniteWaitTimeoutSaturatesInsteadOfTimingOutImmediately(): void
    {
        $pool = $this->createPool([
            'max_connections' => 1,
            'wait_timeout' => PHP_INT_MAX,
        ]);
        $borrowed = $pool->get();
        $result = null;
        $failure = null;

        $this->assertSame(PHP_INT_MAX, $pool->nanosecondsForTest((float) PHP_INT_MAX));
        $this->assertSame(PHP_INT_MAX, $pool->deadlineForTest((float) PHP_INT_MAX));

        Coroutine::create(function () use ($pool, &$result, &$failure): void {
            try {
                $result = $pool->get();
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
        });

        usleep(2_000);
        $pool->release($borrowed);
        usleep(2_000);

        $this->assertNull($failure);
        $this->assertInstanceOf(ConnectionInterface::class, $result);
        $pool->release($result);
        $pool->close();
    }

    public function testForeignAndDoubleReleasesAreRejected(): void
    {
        $pool = $this->createPool();
        $connection = $pool->get();
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
            function () use (&$connections): ConnectionInterface {
                return $connections[] = new PoolConnectionStub;
            },
        );
        $connection = $pool->get();

        $pool->discard($connection);

        $this->assertSame(1, $connection->closeCount);
        $this->assertSame(0, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertNotSame($connection, $replacement = $pool->get());
        $this->assertSame(1, $pool->getCurrentConnections());
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
            static function () use (&$connections): ConnectionInterface {
                return array_shift($connections);
            },
        );
        $connection = $pool->get();

        try {
            $pool->discard($connection);
            $this->fail('Discarding the connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(0, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getBorrowedConnectionsForTest());
        $this->assertNotSame($connection, $replacement = $pool->get());
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

        $idle = $pool->get();
        $pool->release($idle);

        try {
            $pool->discard($idle);
            $this->fail('Discarding an idle connection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not checked out', $exception->getMessage());
        }

        $discarded = $pool->get();
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
            static fn (): ConnectionInterface => $connection
        );
        $pool->get();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already manages');

        $pool->get();
    }

    public function testYieldingFactoriesNeverExceedCapacity(): void
    {
        $factoriesRunning = 0;
        $maximumFactoriesRunning = 0;
        $pool = $this->createPool(
            ['max_connections' => 2, 'wait_timeout' => 0.5],
            function () use (&$factoriesRunning, &$maximumFactoriesRunning): ConnectionInterface {
                ++$factoriesRunning;
                $maximumFactoriesRunning = max($maximumFactoriesRunning, $factoriesRunning);
                usleep(5_000);
                --$factoriesRunning;

                return new PoolConnectionStub;
            }
        );

        $results = parallel(array_fill(0, 8, function () use ($pool): bool {
            $connection = $pool->get();
            usleep(2_000);
            $pool->release($connection);

            return true;
        }));

        $this->assertSame(array_fill(0, 8, true), $results);
        $this->assertSame(2, $maximumFactoriesRunning);
        $this->assertSame(2, $pool->getCurrentConnections());
    }

    public function testCreationFailureWakesAnotherBorrower(): void
    {
        $factoryCalls = 0;
        $pool = $this->createPool(
            ['max_connections' => 1, 'wait_timeout' => 0.2],
            function () use (&$factoryCalls): ConnectionInterface {
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
                    $pool->get();
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                }

                return 'unexpected';
            },
            function () use ($pool): string {
                $connection = $pool->get();
                $pool->release($connection);

                return 'borrowed';
            },
        ]);

        $this->assertSame(['factory failed', 'borrowed'], $results);
    }

    public function testExhaustedPoolTimesOut(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.001]);
        $pool->get();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
        );

        $pool->get();
    }

    #[DataProvider('checkoutCancellationModes')]
    public function testCanceledCheckoutDoesNotCreateAPhantomBorrow(bool $throwException): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 1.0]);
        $channel = new InspectablePoolChannel(1);
        $pool->replaceChannel($channel);
        $borrowed = $pool->get();
        $cancellation = null;
        $unexpectedConnection = null;

        $coroutine = EngineCoroutine::create(function () use (
            $pool,
            &$cancellation,
            &$unexpectedConnection,
        ): void {
            try {
                $unexpectedConnection = $pool->get();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        $this->assertSame(1, $channel->getWaitersForTest());
        $this->assertTrue(EngineCoroutine::cancelById($coroutine->getId(), $throwException));
        $this->assertInstanceOf(CanceledException::class, $cancellation);

        if ($throwException) {
            $this->assertNotSame('The connection pool wait was canceled.', $cancellation->getMessage());
        } else {
            $this->assertSame('The connection pool wait was canceled.', $cancellation->getMessage());
        }

        $this->assertNull($unexpectedConnection);
        $this->assertSame(1, $pool->getCurrentConnections());
        $this->assertSame(1, $pool->getBorrowedConnectionsForTest());
        $this->assertSame(0, $channel->getWaitersForTest());

        $pool->release($borrowed);
        $replacement = $pool->get();
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
        $borrowed = $pool->get();
        $channel = new DeadlinePoolChannel(function () use ($borrowed, $pool): void {
            $pool->release($borrowed);
        });
        $pool->replaceChannel($channel);

        $this->assertSame($borrowed, $returned = $pool->get());
        $this->assertSame(1, $channel->waitCount);

        $pool->release($returned);
    }

    public function testCheckoutPerformsOneFinalPassAfterADeadlineDiscard(): void
    {
        $pool = $this->createPool(['max_connections' => 1, 'wait_timeout' => 0.001]);
        $borrowed = $pool->get();
        $channel = new DeadlinePoolChannel(function () use ($borrowed, $pool): void {
            $pool->discard($borrowed);
        });
        $pool->replaceChannel($channel);

        $this->assertNotSame($borrowed, $replacement = $pool->get());
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
            static function () use (&$connections): ConnectionInterface {
                return array_shift($connections);
            }
        );
        $connection = $pool->get();
        $pool->release($connection);

        $pool->checkIdleConnection();
        $replacement = $pool->get();

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
            static function () use (&$connections): ConnectionInterface {
                return array_shift($connections);
            }
        );
        $connection = $pool->get();
        $pool->release($connection);

        $pool->checkIdleConnection();
        $replacement = $pool->get();

        $this->assertSame(1, $first->closeCount);
        $this->assertSame($second, $replacement);
        $this->assertSame(1, $pool->getCurrentConnections());
    }

    public function testHealthyIdleCheckRequeuesConnection(): void
    {
        $connection = new PoolConnectionStub;
        $pool = $this->createPool([], static fn (): ConnectionInterface => $connection);
        $pool->release($pool->get());

        $pool->checkIdleConnection();

        $this->assertSame(1, $connection->checkCount);
        $this->assertSame(0, $connection->closeCount);
        $this->assertSame($connection, $pool->get());
    }

    public function testFrequencyFailureIsReportedWithoutLosingBorrow(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(m::on(static fn (string $message): bool => str_contains($message, 'hit failed')));
        $container = $this->createContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = new CallbackPool($container, 'test');
        $frequency = m::mock(FrequencyInterface::class);
        $frequency->shouldReceive('hit')->once()->andThrow(new RuntimeException('hit failed'));
        $pool->useFrequency($frequency);

        $connection = $pool->get();

        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $pool->release($connection);
    }

    public function testFrequencyMaintenanceCancellationDiscardsTheUnreturnedBorrow(): void
    {
        $cancellation = new CanceledException('maintenance canceled');
        $connections = [
            new PoolConnectionStub,
            new PoolConnectionStub(closeCallback: static fn (): bool => throw $cancellation),
            new PoolConnectionStub,
        ];
        $pool = $this->createPool(
            ['min_connections' => 0, 'max_connections' => 2],
            static function () use (&$connections): ConnectionInterface {
                return array_shift($connections);
            },
        );
        $first = $pool->get();
        $second = $pool->get();
        $pool->release($first);
        $pool->release($second);
        $pool->useFrequency(new class implements FrequencyInterface, LowFrequencyInterface {
            public function hit(int $number = 1): bool
            {
                return true;
            }

            public function frequency(): float
            {
                return 0.0;
            }

            public function isLowFrequency(): bool
            {
                return true;
            }
        });

        try {
            $pool->get();
            $this->fail('Frequency maintenance was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $first->closeCount);
        $this->assertSame(1, $second->closeCount);
        $this->assertSame(0, $pool->getCurrentConnections());
        $this->assertSame(0, $pool->getBorrowedConnectionsForTest());

        $replacement = $pool->get();
        $pool->release($replacement);
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

class CallbackPool extends Pool
{
    protected Closure $connectionFactory;

    public function __construct(
        ContainerContract $container,
        string $name,
        array $config = [],
        ?Closure $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory
            ?? static fn (): ConnectionInterface => new PoolConnectionStub;

        parent::__construct($container, $name, $config);
    }

    public function useFrequency(FrequencyInterface|LowFrequencyInterface|null $frequency): void
    {
        $this->frequency = $frequency;
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

    public function getBorrowedConnectionsForTest(): int
    {
        return count($this->borrowedConnections);
    }

    protected function createConnection(): ConnectionInterface
    {
        return ($this->connectionFactory)();
    }
}

class ConstantFrequencyPool extends CallbackPool
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

class ThrowingClearableFrequency implements ClearableFrequencyInterface, LowFrequencyInterface
{
    public function clear(): void
    {
        throw new RuntimeException('frequency cleanup failed');
    }

    public function isLowFrequency(): bool
    {
        return false;
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

class PoolConnectionStub implements ConnectionInterface
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
        PoolInterface $pool,
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
