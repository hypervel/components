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
use RuntimeException;
use Swoole\Server as SwooleServer;
use Throwable;

class CreateSwooleTimersTest extends TestCase
{
    public function testRegistersEvictionAndIntervalRefreshTimersForEachSwooleStoreOnWorkerZero(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')->once()->with('cache.stores')->andReturn([
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
        $config->shouldReceive('array')->once()->with('cache.stores')->andReturn([
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

    public function testRollsBackEvictionTimerWhenIntervalTimerRegistrationFails(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')->once()->with('cache.stores')->andReturn([
            'fast' => [
                'driver' => 'swoole',
            ],
        ]);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn($config);

        $timer = new FakeSwooleTimer([41, false]);

        try {
            (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Unable to register the Swoole cache interval refresh timer for store [fast].',
                $exception->getMessage(),
            );
        }

        $this->assertSame([41], $timer->cleared);
    }

    public function testRollsBackEveryEarlierTimerWhenLaterStoreRegistrationFails(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')->once()->with('cache.stores')->andReturn([
            'first' => [
                'driver' => 'swoole',
            ],
            'second' => [
                'driver' => 'swoole',
            ],
        ]);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn($config);

        $timer = new FakeSwooleTimer([11, 12, 13, false]);

        try {
            (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Unable to register the Swoole cache interval refresh timer for store [second].',
                $exception->getMessage(),
            );
        }

        $this->assertSame([13, 12, 11], $timer->cleared);
    }

    public function testPreservesThrownRegistrationFailureWhileAttemptingEveryRollback(): void
    {
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('array')->once()->with('cache.stores')->andReturn([
            'first' => [
                'driver' => 'swoole',
            ],
            'second' => [
                'driver' => 'swoole',
            ],
        ]);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn($config);

        $failure = new RuntimeException('Timer registration failed.');
        $timer = new FakeSwooleTimer([11, 12, $failure], [12]);

        try {
            (new CreateSwooleTimers($container, $timer))->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([12, 11], $timer->cleared);
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

    /**
     * @var list<int>
     */
    public array $cleared = [];

    /**
     * @param list<false|int|Throwable> $results
     * @param list<int> $clearFailures
     */
    public function __construct(
        protected array $results = [],
        protected array $clearFailures = [],
    ) {
    }

    public function tick(int $milliseconds, Closure $callback): int|false
    {
        $this->ticks[] = compact('milliseconds', 'callback');

        if ($this->results !== []) {
            $result = array_shift($this->results);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        return array_key_last($this->ticks);
    }

    public function clear(int $timerId): bool
    {
        $this->cleared[] = $timerId;

        if (in_array($timerId, $this->clearFailures, true)) {
            throw new RuntimeException("Unable to clear timer [{$timerId}].");
        }

        return true;
    }
}
