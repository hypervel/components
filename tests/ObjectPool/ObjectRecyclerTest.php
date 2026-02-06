<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Carbon\Carbon;
use Hypervel\Coordinator\Timer;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\ObjectPool\ObjectRecycler;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ObjectRecyclerTest extends TestCase
{
    public function testStart()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')
            ->once()
            ->with($interval = 1.0, m::type('Closure'))
            ->andReturn($timerId = 99);

        $recycler = new ObjectRecycler(
            m::mock(PoolFactory::class),
            $interval
        );
        $recycler->setTimer($timer);
        $recycler->start();

        $this->assertSame($timerId, $recycler->getTimerId());
    }

    public function testStop()
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')
            ->once()
            ->with($interval = 1.0, m::type('Closure'))
            ->andReturn($timerId = 99);

        $recycler = new ObjectRecycler(
            m::mock(PoolFactory::class),
            $interval
        );
        $recycler->setTimer($timer);
        $recycler->start();

        $timer->shouldReceive('clear')
            ->once()
            ->with($timerId);

        $recycler->stop();

        $this->assertNull($recycler->getTimerId());
    }

    public function testGetLastRecycledAt()
    {
        Carbon::setTestNow('2025-04-01 00:00:00');

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('getLastRecycledAt')
            ->once()
            ->andReturn($lastRecycledAt = Carbon::now());

        $manager = m::mock(PoolFactory::class);
        $manager->shouldReceive('get')
            ->once()
            ->with('foo')
            ->andReturn($pool);

        $recycler = new ObjectRecycler($manager);

        $this->assertSame($lastRecycledAt, $recycler->getLastRecycledAt('foo'));
    }
}
