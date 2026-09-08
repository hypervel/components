<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Container\Container;
use Hypervel\Redis\Listeners\RedisConnectionLifecycleListener;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisConnectionLifecycleListenerTest extends TestCase
{
    public function testTaskCleanupDoesNotResolveAnUnusedManager(): void
    {
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnFalse();
        $container->shouldNotReceive('make');

        (new RedisConnectionLifecycleListener($container))->releaseTaskConnections();
    }

    public function testTaskCleanupReleasesTheConcreteManager(): void
    {
        $manager = m::mock(RedisManager::class);
        $manager->expects('releaseConnections');
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);

        (new RedisConnectionLifecycleListener($container))->releaseTaskConnections();
    }

    public function testTaskCleanupLeavesCustomManagersAlone(): void
    {
        $manager = new stdClass;
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);

        (new RedisConnectionLifecycleListener($container))->releaseTaskConnections();
    }

    public function testProcessCleanupDoesNotResolveUnusedOwners(): void
    {
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnFalse();
        $container->expects('resolved')->with(PoolManager::class)->andReturnFalse();
        $container->shouldNotReceive('make');

        (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testProcessCleanupDiscardsManagerAndPurgesPools(): void
    {
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections');
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll');
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testManagerFailureDoesNotSkipPoolPurgeAndRemainsPrimary(): void
    {
        $managerException = new RuntimeException('Manager discard failed.');
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections')->andThrow($managerException);
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow(new RuntimeException('Pool purge failed.'));
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the manager failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($managerException, $throwable);
        }
    }

    public function testPoolPurgeCancellationSupersedesOrdinaryManagerFailure(): void
    {
        $cancellation = new CanceledException('Pool purge canceled.');
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections')->andThrow(new RuntimeException('Manager discard failed.'));
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow($cancellation);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testPoolPurgeFailurePropagatesAfterManagerCleanup(): void
    {
        $exception = new RuntimeException('Pool purge failed.');
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections');
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow($exception);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the pool purge failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }
}
