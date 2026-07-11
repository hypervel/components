<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Closure;
use Hypervel\Cache\Listeners\CreateSwooleTimers;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTimer;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Server as SwooleServer;

class CreateSwooleTimersTest extends TestCase
{
    public function testRegistersEvictionAndIntervalRefreshTimersForEachSwooleStoreOnWorkerZero(): void
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

        (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 0));

        $this->assertSame([25000, 3000, 10000, 1000], array_column($timer->ticks, 'milliseconds'));
    }

    public function testDoesNotRegisterTimersOnOtherWorkers(): void
    {
        $container = m::mock(Container::class);
        $timer = new FakeSwooleTimer;

        (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 1));

        $this->assertSame([], $timer->ticks);
    }

    public function testDoesNotRegisterTimersOnTaskWorkers(): void
    {
        $container = m::mock(Container::class);
        $timer = new FakeSwooleTimer;

        (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 0, taskworker: true));

        $this->assertSame([], $timer->ticks);
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

        (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 0));

        $timer->ticks[0]['callback']();
        $timer->ticks[1]['callback']();
    }

    private function workerEvent(int $workerId, bool $taskworker = false): AfterWorkerStart
    {
        $server = m::mock(SwooleServer::class);
        $server->taskworker = $taskworker;

        return new AfterWorkerStart($server, $workerId);
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
