<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Pool\Pool;
use Hypervel\Tests\Pool\Fixtures\HeartbeatPoolStub;
use Hypervel\Tests\Pool\Fixtures\KeepaliveConnectionStub;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Event;
use Throwable;

class PoolNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testExhaustedPoolFailsWithoutTryingToBlock(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $pool = new NonCoroutinePool($container, 'test', ['max_connections' => 1]);
        $pool->get();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
        );

        $pool->get();
    }

    #[RunInSeparateProcess]
    public function testDeadlineReleaseRemainsCommittedWhenItsWakeCannotBeCreated(): void
    {
        $pool = $this->createPool();
        $borrowed = $pool->get();
        $replacement = null;
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->get();
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
        $borrowed = $pool->get();
        $replacement = null;
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$replacement): void {
            $replacement = $pool->get();
        });

        $pool->discard($borrowed);
        Event::wait();

        $this->assertInstanceOf(ConnectionInterface::class, $replacement);
        $this->assertNotSame($borrowed, $replacement);
        $pool->release($replacement);
    }

    #[RunInSeparateProcess]
    public function testHeartbeatCreationFailureClosesAcquiredConnection(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $pool = new HeartbeatPoolStub($container, 'test', ['heartbeat' => 1]);
        $exception = null;
        $connected = null;
        $closeCount = null;

        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine::create(function () use ($pool, &$exception, &$connected, &$closeCount): void {
            /** @var KeepaliveConnectionStub $connection */
            $connection = $pool->get();
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

class NonCoroutinePool extends Pool
{
    protected function createConnection(): ConnectionInterface
    {
        return new NonCoroutinePoolConnection;
    }
}

class NonCoroutinePoolConnection implements ConnectionInterface
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
