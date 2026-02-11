<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Carbon\Carbon;
use Hypervel\Contracts\Container\Container;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\ObjectPool\Contracts\Recycler;
use Hypervel\ObjectPool\Strategies\TimeStrategy;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class TimeStrategyTest extends TestCase
{
    public function testGetRecycleInterval()
    {
        $strategy = new TimeStrategy(
            $this->mockContainerWithInterval(1.0)
        );

        $this->assertSame(1.0, $strategy->getRecycleInterval());
    }

    public function testShouldNotRecycle()
    {
        Carbon::setTestNow('2025-04-01 00:00:00');

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('getLastRecycledAt')
            ->once()
            ->andReturn(Carbon::now()->subSeconds(3));

        $strategy = new TimeStrategy(
            $this->mockContainerWithInterval(10.0)
        );

        $this->assertFalse($strategy->shouldRecycle($pool));
    }

    public function testShouldRecycle()
    {
        Carbon::setTestNow('2025-04-01 00:00:00');

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('getLastRecycledAt')
            ->once()
            ->andReturn(Carbon::now()->subSeconds(30));

        $strategy = new TimeStrategy(
            $this->mockContainerWithInterval(10.0)
        );

        $this->assertTrue($strategy->shouldRecycle($pool));
    }

    public function testRecycle()
    {
        Carbon::setTestNow('2025-04-01 00:00:00');

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('getOption->getRecycleRatio')
            ->once()
            ->andReturn(0.5);
        $pool->shouldReceive('getObjectNumberInPool')
            ->once()
            ->andReturn(10);
        $pool->shouldReceive('flushOne')
            ->times(5);
        $pool->shouldReceive('setLastRecycledAt')
            ->once()
            ->withArgs(function (Carbon $carbon) {
                return $carbon->timestamp === Carbon::now()->timestamp;
            });

        $strategy = new TimeStrategy(
            $this->mockContainerWithInterval(10.0)
        );

        $strategy->recycle($pool);
    }

    protected function mockContainerWithInterval(float $interval): Container
    {
        $recycler = m::mock(Recycler::class);
        $recycler->shouldReceive('getInterval')
            ->once()
            ->andReturn($interval);

        $container = m::mock(Container::class);
        $container->shouldReceive('get')
            ->with(Recycler::class)
            ->andReturn($recycler);

        return $container;
    }
}
