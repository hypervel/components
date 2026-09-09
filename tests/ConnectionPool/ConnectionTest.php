<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Closure;
use Hypervel\ConnectionPool\Connection;
use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Tests\ConnectionPool\Fixtures\ActiveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use TypeError;

class ConnectionTest extends TestCase
{
    public function testGetConnectionReturnsTheActiveConnection(): void
    {
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('warning');
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();

        $connection = new ActiveConnectionStub($container, m::mock(ConnectionPool::class));
        $this->assertSame($connection, $connection->getConnection());
    }

    #[DataProvider('acquisitionFailures')]
    public function testGetConnectionDoesNotRetryFailure(string $exceptionClass): void
    {
        $failure = new $exceptionClass('acquisition failed');
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('warning');
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $connection = new ConnectionCallbackStub(
            $container,
            m::mock(ConnectionPool::class),
            static fn (): mixed => throw $failure,
        );
        $caught = null;

        try {
            $connection->getConnection();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame(1, $connection->getActiveConnectionCalls);
    }

    public static function acquisitionFailures(): array
    {
        return [
            'ordinary failure' => [RuntimeException::class],
            'programming error' => [TypeError::class],
        ];
    }

    public function testGetConnectionDoesNotRetryCancellation(): void
    {
        $cancellation = new CanceledException('connection canceled');
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')->never();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $connection = new ConnectionCallbackStub(
            $container,
            m::mock(ConnectionPool::class),
            static fn (): mixed => throw $cancellation,
        );

        try {
            $connection->getConnection();
            $this->fail('Getting the connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $connection->getActiveConnectionCalls);
    }

    public function testConnectionReleasingEvent(): void
    {
        $assert = 0;
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->with(ConnectionReleasing::class)->andReturnUsing(function (ConnectionReleasing $event) use (&$assert) {
            $assert = $event->connection->getLastReleaseTime();
        });

        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOptions')->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));

        $before = hrtime(true) / 1e9;
        $connection->release();
        $after = hrtime(true) / 1e9;

        $this->assertGreaterThanOrEqual($before, $assert);
        $this->assertLessThanOrEqual($after, $assert);
    }

    public function testReleaseListenerCancellationStillReturnsTheConnectionOnce(): void
    {
        $cancellation = new CanceledException('listener canceled');
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow($cancellation);
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection);

        try {
            $connection->release();
            $this->fail('The release listener was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testReleaseListenerCancellationRemainsPrimaryOverCleanupCancellation(): void
    {
        $cancellation = new CanceledException('listener canceled');
        $cleanupCancellation = new CanceledException('release canceled');
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow($cancellation);
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection)->andThrow($cleanupCancellation);

        try {
            $connection->release();
            $this->fail('The release listener was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCancellationWhileLoggingAListenerFailureStillReturnsTheConnectionOnce(): void
    {
        $cancellation = new CanceledException('logging canceled');
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')->once()->andThrow($cancellation);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection);

        try {
            $connection->release();
            $this->fail('Logging the listener failure was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testOrdinaryReleaseListenerFailureIsLoggedAndTheConnectionIsReturned(): void
    {
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with(m::on(static fn (string $message): bool => str_contains($message, 'listener failed')));
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection);

        $connection->release();

        $this->addToAssertionCount(1);
    }

    #[DataProvider('cleanupFailures')]
    public function testLoggerFailureStillReleasesTheConnectionAndPreservesFailurePrecedence(?string $cleanupExceptionClass): void
    {
        $loggingFailure = new RuntimeException('logger failed');
        $cleanupFailure = $cleanupExceptionClass === null ? null : new $cleanupExceptionClass('cleanup failed');
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')->once()->andThrow($loggingFailure);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection)->andReturnUsing(static function () use ($cleanupFailure): void {
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        });
        $caught = null;

        try {
            $connection->release();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cleanupFailure ?? $loggingFailure, $caught);
    }

    public static function cleanupFailures(): array
    {
        return [
            'successful cleanup' => [null],
            'ordinary cleanup failure' => [RuntimeException::class],
            'canceled cleanup' => [CanceledException::class],
        ];
    }

    #[DataProvider('secondaryReportingFailures')]
    public function testReleaseCancellationSurvivesCleanupAndReportingFailures(string $reportingExceptionClass): void
    {
        $cancellation = new CanceledException('listener canceled');
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')->once()->andThrow(new $reportingExceptionClass('secondary reporting failed'));
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->once()->andThrow($cancellation);
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection)->andThrow(new RuntimeException('cleanup failed'));
        $caught = null;

        try {
            $connection->release();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }

    public static function secondaryReportingFailures(): array
    {
        return [
            'ordinary reporting failure' => [RuntimeException::class],
            'canceled reporting' => [CanceledException::class],
        ];
    }

    public function testConfiguredReleaseEventIsNotDispatchedWithoutListeners(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->once()->with(ConnectionReleasing::class)->andReturnFalse();
        $dispatcher->shouldReceive('dispatch')->never();
        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->shouldReceive('release')->once()->with($connection);

        $connection->release();

        $this->addToAssertionCount(1);
    }

    public function testDontHaveEvents(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('dispatch')->never()->with(ConnectionReleasing::class)->andReturnNull();

        $connection = new ActiveConnectionStub($container, $pool = m::mock(ConnectionPool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOptions')->andReturn(PoolOptions::fromArray([]));

        $connection->release();

        $this->assertTrue(true);
    }

    public function testDiscardDelegatesToOwningPool(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $pool = m::mock(ConnectionPool::class);
        $connection = new ActiveConnectionStub($container, $pool);
        $pool->shouldReceive('discard')->once()->with($connection);

        $connection->discard();

        $this->addToAssertionCount(1);
    }

    public function testCheckDoesNotResetActivityTimestamp(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $pool = m::mock(ConnectionPool::class);
        $pool->shouldReceive('release')->once();
        $pool->shouldReceive('getOptions')->twice()->andReturn(PoolOptions::fromArray(['max_idle_time' => 60.0]));
        $connection = new ActiveConnectionStub($container, $pool);

        $connection->release();
        $lastUseTime = $connection->getLastUseTime();

        $this->assertTrue($connection->check());
        $this->assertSame($lastUseTime, $connection->getLastUseTime());
    }

    public function testNullIdleTimeoutKeepsAnUnusedConnectionValid(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $pool = m::mock(ConnectionPool::class);
        $pool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['max_idle_time' => null]));
        $connection = new ActiveConnectionStub($container, $pool);

        $this->assertTrue($connection->check());
        $this->assertSame(0.0, $connection->getLastUseTime());
        $this->assertSame(0.0, $connection->getLastReleaseTime());
    }
}

class ConnectionCallbackStub extends Connection
{
    public int $getActiveConnectionCalls = 0;

    public function __construct(
        ContainerContract $container,
        ConnectionPoolContract $pool,
        protected Closure $getActiveConnectionCallback,
    ) {
        parent::__construct($container, $pool);
    }

    public function getActiveConnection(): mixed
    {
        ++$this->getActiveConnectionCalls;

        return ($this->getActiveConnectionCallback)();
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }
}
