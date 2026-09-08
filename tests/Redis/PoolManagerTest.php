<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\ConnectionPool\Connection;
use Hypervel\Container\Container;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Coordinator\Timer;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;
use WeakReference;

class PoolManagerTest extends TestCase
{
    public function testPoolReturnsSameInstance(): void
    {
        $container = $this->mockContainerWithPools();

        $poolManager = new PoolManager($container);

        $pool1 = $poolManager->pool('default');
        $pool2 = $poolManager->pool('default');

        $this->assertSame($pool1, $pool2);
    }

    public function testPoolReturnsDifferentInstancesForDifferentNames(): void
    {
        $container = $this->mockContainerWithPools();

        $poolManager = new PoolManager($container);

        $pool1 = $poolManager->pool('default');
        $pool2 = $poolManager->pool('cache');

        $this->assertNotSame($pool1, $pool2);
    }

    public function testDirectlyClosedPoolIsReplaced(): void
    {
        $manager = new PoolManager($this->mockContainerWithPools());

        try {
            $original = $manager->pool('default');
            $original->close();
            $replacement = $manager->pool('default');

            $this->assertNotSame($original, $replacement);
            $this->assertFalse($replacement->isClosed());
            $this->assertSame($replacement, $manager->pool('default'));
            $this->assertSame(['default' => $replacement], $manager->getPools());
        } finally {
            $manager->purgeAll();
        }
    }

    #[DataProvider('publicationCleanup')]
    public function testConcurrentResolutionCleansUpTheLoserAndRechecksTheWinner(string $cleanup): void
    {
        $candidates = [];
        $container = $this->publicationContainer($candidates);
        $manager = new PoolManager($container);
        $resolving = new Channel(1);
        $resumeResolution = new Channel(1);
        $closing = new Channel(1);
        $resumeClose = new Channel(1);
        $completed = new Channel(1);
        $first = true;
        $failure = match ($cleanup) {
            'error' => new RuntimeException('loser cleanup failed'),
            'cancellation' => new CanceledException('loser cleanup canceled'),
            default => null,
        };
        $container->afterResolving(RedisPool::class, function () use (&$first, $resolving, $resumeResolution): void {
            if ($first) {
                $first = false;
                $resolving->push(true);
                $this->assertTrue($resumeResolution->pop(1));
            }
        });
        $timerCount = Timer::stats()['num'];
        $child = Coroutine::create(static function () use ($manager, $completed): void {
            try {
                $completed->push([$manager->pool('default'), null]);
            } catch (Throwable $exception) {
                $completed->push([null, $exception]);
            }
        });

        try {
            $this->assertTrue($resolving->pop(1));
            $winner = $manager->pool('default');
            $loser = $candidates[0];
            $this->assertCount(2, $candidates);
            $this->assertSame($timerCount + 1, Timer::stats()['num']);
            $loser->closing = function () use ($cleanup, $failure, $closing, $resumeClose): void {
                if ($failure !== null) {
                    throw $failure;
                }

                if ($cleanup !== 'normal') {
                    $closing->push(true);
                    $this->assertTrue($resumeClose->pop(1));
                }
            };
            $resumeResolution->push(true);

            if (in_array($cleanup, ['close winner', 'replace winner'], true)) {
                $this->assertTrue($closing->pop(1));

                if ($cleanup === 'close winner') {
                    $winner->close();
                } else {
                    $manager->purge('default');
                    $winner = $manager->pool('default');
                }

                $resumeClose->push(true);
            }

            $result = $completed->pop(1);
            $this->assertIsArray($result);
            $this->assertSame($failure, $result[1]);
            $this->assertSame(1, $loser->closeCount);

            if ($failure === null) {
                $this->assertSame($manager->getPools()['default'], $result[0]);
                $this->assertFalse($result[0]->isClosed());
                $this->assertTrue($loser->isClosed());

                if ($cleanup === 'close winner') {
                    $this->assertNotSame($winner, $result[0]);
                } else {
                    $this->assertSame($winner, $result[0]);
                }
            } else {
                $this->assertSame($winner, $manager->pool('default'));
                $this->assertFalse($winner->isClosed());
            }

            $loser->closing = null;
            if (! $loser->isClosed()) {
                $loser->close();
            }
            $manager->purgeAll();
            $this->assertSame($timerCount, Timer::stats()['num']);
        } finally {
            $resumeResolution->close();
            $resumeClose->close();
            Coroutine::join([$child], 1);
            foreach ($candidates as $candidate) {
                $candidate->closing = null;
                $candidate->close();
            }
            $manager->purgeAll();
            $resolving->close();
            $closing->close();
            $completed->close();
        }
    }

    public static function publicationCleanup(): array
    {
        return array_map(static fn (string $cleanup): array => [$cleanup], [
            'normal', 'close winner', 'replace winner', 'error', 'cancellation',
        ]);
    }

    #[DataProvider('publicationEntries')]
    public function testPublicationHandlesAnEntryCreatedDuringResolution(bool $sameInstance): void
    {
        $candidates = [];
        $container = $this->publicationContainer($candidates);
        $manager = new PoolManager($container);
        $first = true;
        $published = null;
        $container->extend(RedisPool::class, function (RedisPool $candidate) use ($manager, &$first, &$published, $sameInstance): RedisPool {
            if (! $first) {
                return $candidate;
            }

            $first = false;
            $published = $manager->pool('default');

            if ($sameInstance) {
                $candidate->close();

                return $published;
            }

            $published->close();

            return $candidate;
        });

        try {
            $result = $manager->pool('default');
            $this->assertSame($sameInstance ? $published : $candidates[0], $result);
            $this->assertFalse($result->isClosed());
            $this->assertSame(0, $result->closeCount);
            $this->assertSame($result, $manager->pool('default'));
        } finally {
            foreach ($candidates as $candidate) {
                $candidate->close();
            }
            $manager->purgeAll();
        }
    }

    public static function publicationEntries(): array
    {
        return ['closed entry' => [false], 'identical candidate' => [true]];
    }

    #[DataProvider('initializationFailures')]
    public function testFailedInitializationDoesNotRetainTheCandidate(bool $warm): void
    {
        $candidates = [];
        $container = $this->publicationContainer($candidates, ['idle_check_interval' => 60.0]);
        $manager = new PoolManager($container);
        $failure = new RuntimeException('initialization failed');
        $weak = null;
        $caught = null;
        $timerCount = Timer::stats()['num'];
        $container->afterResolving(RedisPool::class, static function (RedisPool $pool) use (&$weak, $failure, $warm): void {
            $weak = WeakReference::create($pool);

            if ($warm) {
                $pool->release($pool->borrow());
            }

            throw $failure;
        });

        try {
            try {
                $manager->pool('default');
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($failure, $caught);
            $this->assertSame([], $manager->getPools());
            $candidates = [];
            gc_collect_cycles();
            $this->assertSame($timerCount, Timer::stats()['num']);
            $this->assertNotNull($weak);
            $this->assertNull($weak->get());
        } finally {
            $weak?->get()?->close();
            foreach ($candidates as $candidate) {
                $candidate->close();
            }
            $manager->purgeAll();
        }
    }

    public static function initializationFailures(): array
    {
        return ['cold candidate' => [false], 'warmed candidate' => [true]];
    }

    #[DataProvider('activationFailures')]
    public function testActivationFailureClosesTheCandidateAndPreservesFailurePrecedence(string $activationClass, ?string $cleanupClass): void
    {
        $candidates = [];
        $container = $this->publicationContainer($candidates, ['idle_check_interval' => 60.0]);
        $manager = new PoolManager($container);
        $failure = new $activationClass('activation failed');
        $cleanupFailure = $cleanupClass === null ? null : new $cleanupClass('cleanup failed');
        $timerCount = Timer::stats()['num'];
        $container->afterResolving(RedisPool::class, static function (PublicationRedisPool $pool) use ($failure, $cleanupFailure): void {
            $pool->release($pool->borrow());
            $pool->starting = static fn () => throw $failure;
            $pool->closing = static function () use ($cleanupFailure): void {
                if ($cleanupFailure !== null) {
                    throw $cleanupFailure;
                }
            };
        });
        $caught = null;

        try {
            try {
                $manager->pool('default');
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $expected = ! $failure instanceof CanceledException && $cleanupFailure instanceof CanceledException
                ? $cleanupFailure : $failure;
            $this->assertSame($expected, $caught);
            $this->assertCount(1, $candidates);
            $this->assertTrue($candidates[0]->isClosed());
            $this->assertSame(1, $candidates[0]->closeCount);
            $this->assertSame([], $manager->getPools());
            $this->assertSame($timerCount, Timer::stats()['num']);
        } finally {
            foreach ($candidates as $candidate) {
                $candidate->closing = null;
                $candidate->close();
            }
            $manager->purgeAll();
        }
    }

    public static function activationFailures(): array
    {
        return [
            [RuntimeException::class, null],
            [RuntimeException::class, RuntimeException::class],
            [RuntimeException::class, CanceledException::class],
            [CanceledException::class, null],
            [CanceledException::class, RuntimeException::class],
            [CanceledException::class, CanceledException::class],
        ];
    }

    public function testGetPoolsReturnsOnlyExistingPools(): void
    {
        $poolManager = new PoolManager($this->mockContainerWithPools());

        $this->assertSame([], $poolManager->getPools());

        $default = $poolManager->pool('default');
        $cache = $poolManager->pool('cache');

        $this->assertSame([
            'default' => $default,
            'cache' => $cache,
        ], $poolManager->getPools());
    }

    public function testPurgeAll(): void
    {
        $container = $this->mockContainerWithPools();

        $poolManager = new PoolManager($container);

        $pool1 = $poolManager->pool('default');
        $pool2 = $poolManager->pool('cache');

        $connection1 = $pool1->borrow();
        $connection2 = $pool1->borrow();
        $connection3 = $pool2->borrow();

        $pool1->release($connection1);
        $pool1->release($connection2);
        $pool2->release($connection3);

        $this->assertSame(2, $pool1->getIdleCount());
        $this->assertSame(1, $pool2->getIdleCount());

        $poolManager->purgeAll();

        $this->assertSame(0, $pool1->getIdleCount());
        $this->assertSame(0, $pool2->getIdleCount());
    }

    public function testPurgeAllClearsCachedPools(): void
    {
        $container = $this->mockContainerWithPools();

        $poolManager = new PoolManager($container);

        $original = $poolManager->pool('default');

        $poolManager->purgeAll();

        $fresh = $poolManager->pool('default');

        $this->assertNotSame($original, $fresh);
    }

    public function testPurgeAllDetachesPoolsBeforeClosingThem(): void
    {
        $container = m::mock(ContainerContract::class);
        $original = m::mock(RedisPool::class);
        $replacement = m::mock(RedisPool::class);
        $original->shouldReceive('start')->once();
        $replacement->shouldReceive('start')->once();
        $replacement->shouldReceive('isClosed')->andReturnFalse();
        $container->shouldReceive('make')
            ->with(RedisPool::class, ['name' => 'default'])
            ->twice()
            ->andReturn($original, $replacement);
        $poolManager = new PoolManager($container);
        $resolvedDuringClose = null;
        $original->shouldReceive('close')->once()->andReturnUsing(
            function () use ($poolManager, &$resolvedDuringClose): void {
                $resolvedDuringClose = $poolManager->pool('default');
            }
        );

        $this->assertSame($original, $poolManager->pool('default'));

        $poolManager->purgeAll();

        $this->assertSame($replacement, $resolvedDuringClose);
        $this->assertSame($replacement, $poolManager->pool('default'));
    }

    public function testPurgeAllClosesEveryPoolAndPreservesTheFirstCancellation(): void
    {
        $ordinaryFailure = new RuntimeException('First close failed.');
        $cancellation = new CanceledException('Second close canceled.');
        $laterCancellation = new CanceledException('Third close canceled.');
        $container = m::mock(ContainerContract::class);
        $first = m::mock(RedisPool::class);
        $first->shouldReceive('start')->once();
        $first->expects('close')->andThrow($ordinaryFailure);
        $second = m::mock(RedisPool::class);
        $second->shouldReceive('start')->once();
        $second->expects('close')->andThrow($cancellation);
        $third = m::mock(RedisPool::class);
        $third->shouldReceive('start')->once();
        $third->expects('close')->andThrow($laterCancellation);
        $container->expects('make')
            ->with(RedisPool::class, m::type('array'))
            ->times(3)
            ->andReturn($first, $second, $third);
        $poolManager = new PoolManager($container);
        $poolManager->pool('first');
        $poolManager->pool('second');
        $poolManager->pool('third');

        try {
            $poolManager->purgeAll();
            $this->fail('Expected the first cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testPurgeOnlyRemovesNamedPool(): void
    {
        $container = $this->mockContainerWithPools();

        $poolManager = new PoolManager($container);

        $defaultPool = $poolManager->pool('default');
        $cachePool = $poolManager->pool('cache');

        $defaultConnection1 = $defaultPool->borrow();
        $defaultConnection2 = $defaultPool->borrow();
        $cacheConnection = $cachePool->borrow();

        $defaultPool->release($defaultConnection1);
        $defaultPool->release($defaultConnection2);
        $cachePool->release($cacheConnection);

        $this->assertSame(2, $defaultPool->getIdleCount());
        $this->assertSame(1, $cachePool->getIdleCount());

        $poolManager->purge('default');

        $this->assertSame(0, $defaultPool->getIdleCount());

        $this->assertSame(1, $cachePool->getIdleCount());
        $this->assertSame($cachePool, $poolManager->pool('cache'));

        $freshDefaultPool = $poolManager->pool('default');
        $this->assertNotSame($defaultPool, $freshDefaultPool);
    }

    public function testPurgeDetachesPoolBeforeClosingIt(): void
    {
        $container = m::mock(ContainerContract::class);
        $original = m::mock(RedisPool::class);
        $replacement = m::mock(RedisPool::class);
        $original->shouldReceive('start')->once();
        $replacement->shouldReceive('start')->once();
        $replacement->shouldReceive('isClosed')->andReturnFalse();
        $container->shouldReceive('make')
            ->with(RedisPool::class, ['name' => 'default'])
            ->twice()
            ->andReturn($original, $replacement);
        $poolManager = new PoolManager($container);
        $resolvedDuringClose = null;
        $original->shouldReceive('close')->once()->andReturnUsing(
            function () use ($poolManager, &$resolvedDuringClose): void {
                $resolvedDuringClose = $poolManager->pool('default');
            }
        );

        $this->assertSame($original, $poolManager->pool('default'));

        $poolManager->purge('default');

        $this->assertSame($replacement, $resolvedDuringClose);
        $this->assertSame($replacement, $poolManager->pool('default'));
    }

    /**
     * Build a container that records independently constructed pools.
     */
    private function publicationContainer(array &$candidates, array $poolOptions = []): Container
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance(RedisConfig::class, new RedisConfig(new Repository(['database' => ['redis' => [
            'options' => [],
            'default' => ['host' => '127.0.0.1', 'port' => 6379, 'pool' => ['heartbeat_interval' => 60.0, ...$poolOptions]],
        ]]])));
        $container->bind(RedisPool::class, static function (Container $container, array $parameters) use (&$candidates): RedisPool {
            return $candidates[] = new PublicationRedisPool($container, $parameters['name']);
        });

        return $container;
    }

    /**
     * Mock a container with Redis pools.
     */
    private function mockContainerWithPools(): m\MockInterface|ContainerContract
    {
        $connectionConfig = [
            'host' => 'localhost',
            'port' => 6379,
            'database' => 0,
            'timeout' => null,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 60.0,
            ],
        ];

        $redisConfig = m::mock(RedisConfig::class);
        $redisConfig->shouldReceive('connectionConfig')->andReturn($connectionConfig);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(RedisConfig::class)->andReturn($redisConfig);
        $container->shouldReceive('has')->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('make')->with(RedisPool::class, m::any())->andReturnUsing(
            fn ($class, $arguments) => new PoolManagerTestPool($container, $arguments['name'])
        );

        return $container;
    }
}

class PublicationRedisPool extends RedisPool
{
    public int $closeCount = 0;

    public ?Closure $starting = null;

    public ?Closure $closing = null;

    /**
     * Start maintenance before running the controlled activation callback.
     */
    public function start(): void
    {
        parent::start();

        if ($this->starting !== null) {
            ($this->starting)();
        }
    }

    /**
     * Close the pool after running the controlled cleanup callback.
     */
    public function close(): void
    {
        ++$this->closeCount;

        try {
            if ($this->closing !== null) {
                ($this->closing)();
            }
        } finally {
            parent::close();
        }
    }

    /**
     * Create an inert connection for initialization and lifecycle tests.
     */
    protected function createConnection(): PoolConnection
    {
        return new PoolManagerTestConnection($this->container, $this);
    }
}

class PoolManagerTestPool extends RedisPool
{
    protected function createConnection(): PoolConnection
    {
        return new PoolManagerTestConnection($this->container, $this);
    }
}

class PoolManagerTestConnection extends Connection
{
    public function close(): bool
    {
        return true;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function getActiveConnection(): static
    {
        return $this;
    }
}
