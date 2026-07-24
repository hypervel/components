<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Container\Container;
use Hypervel\Redis\Listeners\RedisConnectionLifecycleListener;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

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
        $container->expects('resolved')->with(PoolFactory::class)->andReturnFalse();
        $container->shouldNotReceive('make');

        (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testProcessCleanupDiscardsManagerAndFlushesPoolFactory(): void
    {
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections');
        $factory = m::mock(PoolFactory::class);
        $factory->expects('flushAll');
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolFactory::class)->andReturnTrue();
        $container->expects('make')->with(PoolFactory::class)->andReturn($factory);

        (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testManagerFailureDoesNotSkipPoolFlushAndRemainsPrimary(): void
    {
        $managerException = new RuntimeException('Manager discard failed.');
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections')->andThrow($managerException);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('flushAll')->andThrow(new RuntimeException('Pool flush failed.'));
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolFactory::class)->andReturnTrue();
        $container->expects('make')->with(PoolFactory::class)->andReturn($factory);

        try {
            (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the manager failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($managerException, $throwable);
        }
    }

    public function testPoolFactoryFailurePropagatesAfterManagerCleanup(): void
    {
        $exception = new RuntimeException('Pool flush failed.');
        $manager = m::mock(RedisManager::class);
        $manager->expects('discardConnections');
        $factory = m::mock(PoolFactory::class);
        $factory->expects('flushAll')->andThrow($exception);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('redis')->andReturnTrue();
        $container->expects('make')->with('redis')->andReturn($manager);
        $container->expects('resolved')->with(PoolFactory::class)->andReturnTrue();
        $container->expects('make')->with(PoolFactory::class)->andReturn($factory);

        try {
            (new RedisConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the pool factory failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }
}
