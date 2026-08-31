<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Closure;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Connection;
use Hypervel\Pool\Events\ReleaseConnection;
use Hypervel\Pool\Pool;
use Hypervel\Pool\PoolOption;
use Hypervel\Tests\Pool\Fixtures\ActiveConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class ConnectionTest extends TestCase
{
    public function testGetActiveConnectionAgain(): void
    {
        $container = m::mock(ContainerContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')->withAnyArgs()->once()->andReturnTrue();
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnTrue();
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->once()->andReturn($logger);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();

        $connection = new ActiveConnectionStub($container, m::mock(Pool::class));
        $this->assertSame($connection, $connection->getConnection());
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
            m::mock(Pool::class),
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

    public function testReleaseConnectionEvent(): void
    {
        $assert = 0;
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnTrue();
        $container->shouldReceive('make')->with('events')->andReturn($dispatcher = m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('dispatch')->once()->with(ReleaseConnection::class)->andReturnUsing(function (ReleaseConnection $event) use (&$assert) {
            $assert = $event->connection->getLastReleaseTime();
        });

        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: [ReleaseConnection::class]));

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
        $dispatcher->shouldReceive('dispatch')->once()->andThrow($cancellation);
        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('getOption')->once()->andReturn(new PoolOption(events: [ReleaseConnection::class]));
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
        $dispatcher->shouldReceive('dispatch')->once()->andThrow($cancellation);
        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('getOption')->once()->andReturn(new PoolOption(events: [ReleaseConnection::class]));
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
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('getOption')->once()->andReturn(new PoolOption(events: [ReleaseConnection::class]));
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
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('getOption')->once()->andReturn(new PoolOption(events: [ReleaseConnection::class]));
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
        $dispatcher->shouldReceive('dispatch')->never()->with(ReleaseConnection::class)->andReturnNull();

        $connection = new ActiveConnectionStub($container, $pool = m::mock(Pool::class));
        $pool->shouldReceive('release')->withAnyArgs()->andReturnNull();
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: []));

        $connection->release();

        $this->assertTrue(true);
    }

    public function testDiscardDelegatesToOwningPool(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->once()->andReturnFalse();
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $pool = m::mock(Pool::class);
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
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('release')->once();
        $pool->shouldReceive('getOption')->twice()->andReturn(new PoolOption(maxIdleTime: 60.0));
        $connection = new ActiveConnectionStub($container, $pool);

        $connection->release();
        $lastUseTime = $connection->getLastUseTime();

        $this->assertTrue($connection->check());
        $this->assertSame($lastUseTime, $connection->getLastUseTime());
    }
}

class ConnectionCallbackStub extends Connection
{
    public int $getActiveConnectionCalls = 0;

    public function __construct(
        ContainerContract $container,
        PoolInterface $pool,
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
