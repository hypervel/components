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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class IdleConnectionMonitorTest extends TestCase
{
    use VerifiesDoubles;

    public function testMonitorChecksIdleConnectionsAtItsInterval(): void
    {
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(false);
        $pool->expects('checkIdleConnection')->times(minimum: 1);
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
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(false);
        $timer = Double::for(Timer::class);
        $timer->expects('tick')->with(5.0, Argument::type('callable'))->times(2)->returns(1, 2);
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
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(true);
        $timer = Double::for(Timer::class);
        $timer->expects('tick')->never();

        (new IdleConnectionMonitor($pool, 1.0, $timer))->start();
    }

    public function testSynchronousStartupReentryDoesNotCreateAnotherTimer(): void
    {
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(false);
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
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(false);
        $failure = new RuntimeException('timer creation failed');
        $timer = Double::for(Timer::class);
        $attempts = 0;
        $timer->expects('tick')->with(1.0, Argument::type('callable'))->times(2)->resolves(
            static function () use (&$attempts, $failure): int {
                if (++$attempts === 1) {
                    throw $failure;
                }

                return 1;
            },
        );
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
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(false);
        $pool->expects('checkIdleConnection')->never();
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
        $pool = Double::for(ConnectionPool::class);
        $pool->allows('isClosed')->returns(false);
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
        $checked = [];
        $first->expects('check')->resolves(static function () use (&$checked): bool {
            $checked[] = 'first';

            return true;
        });
        $second->expects('check')->resolves(static function () use (&$checked): bool {
            $checked[] = 'second';

            return false;
        });
        $first->expects('close');
        $second->expects('close');
        $pool->release($first);
        $pool->release($second);
        $callback = null;
        $timer = Double::for(Timer::class);
        $timer->expects('tick')->with(1.0, Argument::type('callable'))->resolves(
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
            $this->assertSame(['first', 'second'], $checked);
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

        new IdleConnectionMonitor(Double::for(ConnectionPool::class), $interval);
    }

    public static function invalidIntervals(): array
    {
        return [[0.0], [-1.0], [INF], [NAN]];
    }
}
