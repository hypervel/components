<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Hypervel\Cache\Listeners\CreateSwooleTimers;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTimer;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\OnManagerStart;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CreateSwooleTimersTest extends TestCase
{
    public function testRegistersEvictionAndIntervalRefreshTimersForEachSwooleStore(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')->once()->with('cache.stores', [])->andReturn([
            'fast' => [
                'driver' => 'swoole',
                'eviction_interval' => 25000,
                'interval_refresh_interval' => 3000,
            ],
            'defaulted' => [
                'driver' => 'swoole',
            ],
            'redis' => [
                'driver' => 'redis',
            ],
        ]);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn($config);

        $timer = new FakeSwooleTimer;

        (new CreateSwooleTimers($container, $timer))->handle(m::mock(OnManagerStart::class));

        $this->assertSame([25000, 3000, 10000, 1000], array_column($timer->ticks, 'milliseconds'));
    }

    public function testTimerCallbacksCallTheConfiguredStore(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')->once()->with('cache.stores', [])->andReturn([
            'fast' => [
                'driver' => 'swoole',
            ],
        ]);

        $store = m::mock(SwooleStore::class);
        $store->shouldReceive('evictRecords')->once();
        $store->shouldReceive('refreshIntervalCaches')->once();

        $repository = m::mock();
        $repository->shouldReceive('getStore')->twice()->andReturn($store);

        $cache = m::mock();
        $cache->shouldReceive('store')->twice()->with('fast')->andReturn($repository);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn($config);
        $container->shouldReceive('make')->twice()->with('cache')->andReturn($cache);

        $timer = new FakeSwooleTimer;

        (new CreateSwooleTimers($container, $timer))->handle(m::mock(OnManagerStart::class));

        $timer->ticks[0]['callback']();
        $timer->ticks[1]['callback']();
    }
}

class FakeSwooleTimer extends SwooleTimer
{
    /**
     * @var list<array{milliseconds: int, callback: Closure}>
     */
    public array $ticks = [];

    public function tick(int $milliseconds, Closure $callback): int|false
    {
        $this->ticks[] = compact('milliseconds', 'callback');

        return array_key_last($this->ticks);
    }
}
