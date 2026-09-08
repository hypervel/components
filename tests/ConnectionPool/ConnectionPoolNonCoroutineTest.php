<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\ConnectionPool\Exceptions\PoolExhaustedException;
use Hypervel\Container\Container;
use Hypervel\Contracts\ConnectionPool\Connection;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Tests\ConnectionPool\Fixtures\HeartbeatPoolStub;
use Hypervel\Tests\ConnectionPool\Fixtures\KeepaliveConnectionStub;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Event;
use Throwable;

class ConnectionPoolNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testExhaustedPoolFailsWithoutTryingToBlock(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $pool = new NonCoroutinePool($container, 'test', ['max_connections' => 1]);
        $pool->borrow();

        $this->expectException(PoolExhaustedException::class);
        $this->expectExceptionMessage(
            'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
        );

        $pool->borrow();
    }

    #[RunInSeparateProcess]
    public function testDeadlineReleaseRemainsCommittedWhenItsWakeCannotBeCreated(): void
    {
        $pool = $this->createPool();
        $borrowed = $pool->borrow();
        $replacement = null;
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->borrow();
        });

        $pool->release($borrowed);
        Event::wait();

        $this->assertSame($borrowed, $replacement);
        $pool->release($replacement);
    }

    #[RunInSeparateProcess]
    public function testDeadlineDiscardRemainsCommittedWhenItsWakeCannotBeCreated(): void
    {
        $pool = $this->createPool();
        $borrowed = $pool->borrow();
        $replacement = null;
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->borrow();
        });

        $pool->discard($borrowed);
        Event::wait();

        $this->assertInstanceOf(Connection::class, $replacement);
        $this->assertNotSame($borrowed, $replacement);
        $pool->release($replacement);
    }

    #[RunInSeparateProcess]
    public function testHeartbeatCreationFailureClosesAcquiredConnection(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $pool = new HeartbeatPoolStub($container, 'test', ['heartbeat_interval' => 1]);
        $exception = null;
        $connected = null;
        $closeCount = null;

        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$exception, &$connected, &$closeCount): void {
            /** @var KeepaliveConnectionStub $connection */
            $connection = $pool->borrow();
            $connection->setActiveConnection(new stdClass);

            try {
                $connection->reconnect();
            } catch (Throwable $throwable) {
                $exception = $throwable;
                $connected = $connection->check();
                $closeCount = $connection->closeCount;
            } finally {
                $connection->discard();
            }
        });

        Event::wait();

        $this->assertInstanceOf(CoroutineCreateException::class, $exception);
        $this->assertFalse($connected);
        $this->assertSame(1, $closeCount);
    }

    private function createPool(): NonCoroutinePool
    {
        $container = new Container;
        Container::setInstance($container);

        return new NonCoroutinePool($container, 'test', [
            'max_connections' => 1,
            'wait_timeout' => 0.001,
        ]);
    }
}

class NonCoroutinePool extends ConnectionPool
{
    protected function createConnection(): Connection
    {
        return new NonCoroutinePoolConnection;
    }
}

class NonCoroutinePoolConnection implements Connection
{
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
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function release(): void
    {
    }

    public function discard(): void
    {
    }
}
