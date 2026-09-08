<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\ConnectionPool\IdleConnectionMonitor;
use Hypervel\Container\Container;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Tests\ConnectionPool\Fixtures\FooPool;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class IdleConnectionMonitorTest extends TestCase
{
    public function testMonitorChecksIdleConnectionsAtItsInterval(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnFalse();
        $pool->shouldReceive('checkIdleConnection')->atLeast()->once();
        $timerCount = Timer::stats()['num'];
        $monitor = new IdleConnectionMonitor($pool, 0.001);

        $this->assertSame($timerCount, Timer::stats()['num']);

        try {
            $monitor->start();
            Coroutine::sleep(0.005);
        } finally {
            $monitor->stop();
        }

        $this->assertSame($timerCount, Timer::stats()['num']);
    }

    public function testStartAndStopAreIdempotentAndPermitRestart(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnFalse();
        $timer = m::mock(Timer::class);
        $timer->expects('tick')->with(5.0, m::type('callable'))->twice()->andReturn(1, 2);
        $timer->expects('clear')->with(1);
        $timer->expects('clear')->with(2);
        $monitor = new IdleConnectionMonitor($pool, 5.0, $timer);

        $monitor->start();
        $monitor->start();
        $monitor->stop();
        $monitor->stop();
        $monitor->start();
        $monitor->stop();
    }

    public function testClosedPoolCannotStartAMonitor(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnTrue();
        $timer = m::mock(Timer::class);
        $timer->shouldNotReceive('tick');

        (new IdleConnectionMonitor($pool, 1.0, $timer))->start();
    }

    public function testSynchronousStartupReentryDoesNotCreateAnotherTimer(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnFalse();
        $monitor = new IdleConnectionMonitor($pool, 60.0);
        $timerCount = Timer::stats()['num'];
        $startupCalls = 0;
        Coroutine::afterCreated(static function () use ($monitor, &$startupCalls): void {
            ++$startupCalls;
            $monitor->start();
        });

        try {
            $monitor->start();

            $this->assertSame(1, $startupCalls);
            $this->assertSame($timerCount + 1, Timer::stats()['num']);
        } finally {
            $monitor->stop();
        }

        $this->assertSame($timerCount, Timer::stats()['num']);
    }

    public function testCloseDuringStartupClearsTheUnpublishedTimer(): void
    {
        $pool = new FooPool(new Container, 'test');
        $monitor = new IdleConnectionMonitor($pool, 60.0);
        $timerCount = Timer::stats()['num'];
        Coroutine::afterCreated(static fn () => $pool->close());

        try {
            $monitor->start();
            $monitor->start();

            $this->assertTrue($pool->isClosed());
            $this->assertSame($timerCount, Timer::stats()['num']);
        } finally {
            $monitor->stop();
            $pool->close();
        }
    }

    public function testStartupFailureAllowsTheNextStart(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnFalse();
        $failure = new RuntimeException('timer creation failed');
        $timer = m::mock(Timer::class);
        $timer->expects('tick')->with(1.0, m::type('callable'))->ordered()->andThrow($failure);
        $timer->expects('tick')->with(1.0, m::type('callable'))->ordered()->andReturn(1);
        $timer->expects('clear')->with(1);
        $monitor = new IdleConnectionMonitor($pool, 1.0, $timer);
        $caught = null;

        try {
            $monitor->start();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $monitor->start();
        $monitor->stop();
    }

    public function testWorkerExitStopsWithoutCheckingIdleConnections(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnFalse();
        $pool->shouldNotReceive('checkIdleConnection');
        $monitor = new IdleConnectionMonitor($pool, 60.0);
        $timerCount = Timer::stats()['num'];

        try {
            $monitor->start();
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

            $this->assertSame($timerCount, Timer::stats()['num']);
        } finally {
            $monitor->stop();
        }
    }

    public function testEachMonitorOwnsItsTimer(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->allows('isClosed')->andReturnFalse();
        $first = new IdleConnectionMonitor($pool, 60.0);
        $second = new IdleConnectionMonitor($pool, 60.0);
        $timerCount = Timer::stats()['num'];

        try {
            $first->start();
            $second->start();
            $this->assertSame($timerCount + 2, Timer::stats()['num']);

            $first->stop();
            $this->assertSame($timerCount + 1, Timer::stats()['num']);
        } finally {
            $first->stop();
            $second->stop();
        }

        $this->assertSame($timerCount, Timer::stats()['num']);
    }

    public function testEachTickChecksOneIdleConnectionInFifoOrder(): void
    {
        $pool = new FooPool(new Container, 'test', ['min_retained_connections' => 2]);
        $first = $pool->borrow();
        $second = $pool->borrow();
        $first->expects('check')->once()->globally()->ordered()->andReturnTrue();
        $second->expects('check')->once()->globally()->ordered()->andReturnFalse();
        $first->expects('close');
        $second->expects('close');
        $pool->release($first);
        $pool->release($second);
        $callback = null;
        $timer = m::mock(Timer::class);
        $timer->expects('tick')->with(1.0, m::type('callable'))->andReturnUsing(
            static function (float $interval, callable $check) use (&$callback): int {
                $callback = $check;

                return 1;
            },
        );
        $timer->expects('clear')->with(1);
        $monitor = new IdleConnectionMonitor($pool, 1.0, $timer);

        try {
            $monitor->start();
            $callback(false);
            $this->assertSame(2, $pool->getIdleCount());

            $callback(false);
            $this->assertSame(1, $pool->getManagedCount());
            $this->assertSame(1, $pool->getIdleCount());
        } finally {
            $monitor->stop();
            $pool->close();
        }
    }

    #[DataProvider('invalidIntervals')]
    public function testInvalidIntervalsAreRejected(float $interval): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IdleConnectionMonitor(m::mock(ConnectionPool::class), $interval);
    }

    public static function invalidIntervals(): array
    {
        return [[0.0], [-1.0], [INF], [NAN]];
    }
}
