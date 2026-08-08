<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Listeners\RegisterSwooleMaintenanceTimers;
use Hypervel\Cache\SwooleStore;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Server as SwooleServer;
use Throwable;

class RegisterSwooleMaintenanceTimersTest extends TestCase
{
    public function testRegistersEvictionAndIntervalRefreshTimersForEachSwooleStoreOnWorkerZero(): void
    {
        $config = $this->config([
            'fast' => [
                'driver' => 'swoole',
                'eviction_interval' => 25000,
                'interval_refresh_interval' => 3000,
            ],
            'defaulted' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
            'redis' => [
                'driver' => 'redis',
            ],
        ]);

        $container = $this->containerWithSwooleStores('fast', 'defaulted');
        $timer = new FakeCoordinatorTimer;

        (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
            ->handle($this->workerEvent(workerId: 0));

        $this->assertSame([25.0, 3.0, 10.0, 1.0], array_column($timer->ticks, 'seconds'));
    }

    public function testDoesNotRegisterTimersOnOtherWorkers(): void
    {
        $container = m::mock(Container::class);
        $timer = new FakeCoordinatorTimer;

        (new RegisterSwooleMaintenanceTimers($container, $timer, $this->config([])))
            ->handle($this->workerEvent(workerId: 1));

        $this->assertSame([], $timer->ticks);
    }

    public function testDoesNotRegisterTimersOnTaskWorkers(): void
    {
        $container = m::mock(Container::class);
        $timer = new FakeCoordinatorTimer;

        (new RegisterSwooleMaintenanceTimers($container, $timer, $this->config([])))
            ->handle($this->workerEvent(workerId: 0, taskworker: true));

        $this->assertSame([], $timer->ticks);
    }

    public function testTimerCallbacksCallTheConfiguredStore(): void
    {
        $config = $this->config([
            'fast' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
        ]);

        $store = m::mock(SwooleStore::class);
        $store->shouldReceive('evictRecords')->once();
        $store->shouldReceive('refreshIntervalCaches')->once();

        $repository = m::mock();
        $repository->shouldReceive('getStore')->once()->andReturn($store);

        $cache = m::mock();
        $cache->shouldReceive('store')->once()->with('fast')->andReturn($repository);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $timer = new FakeCoordinatorTimer;

        (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
            ->handle($this->workerEvent(workerId: 0));

        $timer->ticks[0]['callback']();
        $timer->ticks[1]['callback']();
    }

    public function testResolvesEveryStoreBeforeRegisteringTimers(): void
    {
        $config = $this->config([
            'first' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
            'second' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
        ]);
        $firstRepository = m::mock();
        $firstRepository->shouldReceive('getStore')->once()->andReturn(m::mock(SwooleStore::class));

        $failure = new RuntimeException('Store resolution failed.');
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->with('first')->andReturn($firstRepository);
        $cache->shouldReceive('store')->once()->with('second')->andThrow($failure);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->twice()->with('cache')->andReturn($cache);

        $timer = new FakeCoordinatorTimer;

        try {
            (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
                ->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected store resolution to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([], $timer->ticks);
    }

    public function testRollsBackEvictionTimerWhenIntervalTimerRegistrationFails(): void
    {
        $config = $this->config([
            'fast' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
        ]);
        $container = $this->containerWithSwooleStores('fast');
        $failure = new RuntimeException('Timer registration failed.');
        $timer = new FakeCoordinatorTimer([41, $failure]);

        try {
            (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
                ->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([41], $timer->cleared);
    }

    public function testRollsBackEveryEarlierTimerWhenLaterStoreRegistrationFails(): void
    {
        $config = $this->config([
            'first' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
            'second' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
        ]);
        $container = $this->containerWithSwooleStores('first', 'second');
        $failure = new RuntimeException('Timer registration failed.');
        $timer = new FakeCoordinatorTimer([11, 12, 13, $failure]);

        try {
            (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
                ->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([13, 12, 11], $timer->cleared);
    }

    public function testPreservesThrownRegistrationFailureWhileAttemptingEveryRollback(): void
    {
        $config = $this->config([
            'first' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
            'second' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
        ]);
        $container = $this->containerWithSwooleStores('first', 'second');
        $failure = new RuntimeException('Timer registration failed.');
        $timer = new FakeCoordinatorTimer([11, 12, $failure], [12]);

        try {
            (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
                ->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected timer registration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([12, 11], $timer->cleared);
    }

    /**
     * @param array<string, mixed> $intervalConfig
     */
    #[DataProvider('invalidIntervals')]
    public function testRejectsInvalidIntervalsBeforeTimerRegistration(
        array $intervalConfig,
        string $invalidKey,
    ): void {
        $config = $this->config([
            'invalid' => [
                'driver' => 'swoole',
                ...$intervalConfig,
            ],
        ]);
        $container = m::mock(Container::class);
        $timer = new FakeCoordinatorTimer;

        try {
            (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
                ->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected invalid timer configuration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                "cache.stores.invalid.{$invalidKey}",
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $timer->ticks);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidIntervals(): array
    {
        return [
            'missing eviction interval' => [[
                'interval_refresh_interval' => 1000,
            ], 'eviction_interval'],
            'wrong eviction interval type' => [[
                'eviction_interval' => '10000',
                'interval_refresh_interval' => 1000,
            ], 'eviction_interval'],
            'zero eviction interval' => [[
                'eviction_interval' => 0,
                'interval_refresh_interval' => 1000,
            ], 'eviction_interval'],
            'negative eviction interval' => [[
                'eviction_interval' => -1,
                'interval_refresh_interval' => 1000,
            ], 'eviction_interval'],
            'missing refresh interval' => [[
                'eviction_interval' => 10000,
            ], 'interval_refresh_interval'],
            'wrong refresh interval type' => [[
                'eviction_interval' => 10000,
                'interval_refresh_interval' => '1000',
            ], 'interval_refresh_interval'],
            'zero refresh interval' => [[
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 0,
            ], 'interval_refresh_interval'],
            'negative refresh interval' => [[
                'eviction_interval' => 10000,
                'interval_refresh_interval' => -1,
            ], 'interval_refresh_interval'],
        ];
    }

    public function testRejectsAnInvalidLaterStoreBeforeRegisteringEarlierTimers(): void
    {
        $config = $this->config([
            'first' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 1000,
            ],
            'second' => [
                'driver' => 'swoole',
                'eviction_interval' => 10000,
                'interval_refresh_interval' => 0,
            ],
        ]);
        $container = m::mock(Container::class);
        $timer = new FakeCoordinatorTimer;

        try {
            (new RegisterSwooleMaintenanceTimers($container, $timer, $config))
                ->handle($this->workerEvent(workerId: 0));

            $this->fail('Expected invalid timer configuration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'cache.stores.second.interval_refresh_interval',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $timer->ticks);
    }

    /**
     * @param array<string, array<string, mixed>> $stores
     */
    private function config(array $stores): Repository
    {
        return new Repository([
            'cache' => [
                'stores' => $stores,
            ],
        ]);
    }

    /**
     * Create a container that resolves the given Swoole cache stores.
     */
    private function containerWithSwooleStores(string ...$names): Container
    {
        $cache = m::mock();

        foreach ($names as $name) {
            $repository = m::mock();
            $repository->shouldReceive('getStore')->once()->andReturn(m::mock(SwooleStore::class));
            $cache->shouldReceive('store')->once()->with($name)->andReturn($repository);
        }

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->times(count($names))->with('cache')->andReturn($cache);

        return $container;
    }

    private function workerEvent(int $workerId, bool $taskworker = false): AfterWorkerStart
    {
        $server = m::mock(SwooleServer::class);
        $server->taskworker = $taskworker;

        return new AfterWorkerStart($server, $workerId);
    }
}

class FakeCoordinatorTimer extends Timer
{
    /**
     * @var list<array{seconds: float, callback: callable, identifier: string}>
     */
    public array $ticks = [];

    /**
     * @var list<int>
     */
    public array $cleared = [];

    /**
     * @param list<int|Throwable> $results
     * @param list<int> $clearFailures
     */
    public function __construct(
        protected array $results = [],
        protected array $clearFailures = [],
    ) {
        parent::__construct();
    }

    public function tick(
        float $seconds,
        callable $callback,
        string $identifier = Constants::WORKER_EXIT,
    ): int {
        $this->ticks[] = compact('seconds', 'callback', 'identifier');

        if ($this->results !== []) {
            $result = array_shift($this->results);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        return count($this->ticks);
    }

    public function clear(int $timerId): void
    {
        $this->cleared[] = $timerId;

        if (in_array($timerId, $this->clearFailures, true)) {
            throw new RuntimeException("Unable to clear timer [{$timerId}].");
        }
    }
}
