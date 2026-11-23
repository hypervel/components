<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Container\Container;
use Hypervel\Redis\Listeners\RedisConnectionLifecycleListener;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisManager;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisConnectionLifecycleListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testTaskCleanupDoesNotResolveAnUnusedManager(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(false);
        $container->expects('make')->never();

        (new RedisConnectionLifecycleListener($container->instance()))->releaseTaskConnections();
    }

    public function testTaskCleanupReleasesTheConcreteManager(): void
    {
        $manager = Double::for(RedisManager::class)->strict();
        $manager->expects('releaseConnections');
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(true);
        $container->expects('make')->with('redis')->returns($manager);

        (new RedisConnectionLifecycleListener($container->instance()))->releaseTaskConnections();
    }

    public function testTaskCleanupLeavesCustomManagersAlone(): void
    {
        $manager = new stdClass;
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(true);
        $container->expects('make')->with('redis')->returns($manager);

        (new RedisConnectionLifecycleListener($container->instance()))->releaseTaskConnections();
    }

    public function testProcessCleanupDoesNotResolveUnusedOwners(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(false);
        $container->expects('resolved')->with(PoolManager::class)->returns(false);
        $container->expects('make')->never();

        (new RedisConnectionLifecycleListener($container->instance()))->discardProcessConnections();
    }

    public function testProcessCleanupDiscardsManagerAndPurgesPools(): void
    {
        $manager = Double::for(RedisManager::class)->strict();
        $manager->expects('discardConnections');
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll');
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(true);
        $container->expects('make')->with('redis')->returns($manager);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        (new RedisConnectionLifecycleListener($container->instance()))->discardProcessConnections();
    }

    public function testManagerFailureDoesNotSkipPoolPurgeAndRemainsPrimary(): void
    {
        $managerException = new RuntimeException('Manager discard failed.');
        $manager = Double::for(RedisManager::class)->strict();
        $manager->expects('discardConnections')->throws($managerException);
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws(new RuntimeException('Pool purge failed.'));
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(true);
        $container->expects('make')->with('redis')->returns($manager);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new RedisConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected the manager failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($managerException, $throwable);
        }
    }

    public function testPoolPurgeCancellationSupersedesOrdinaryManagerFailure(): void
    {
        $cancellation = new CanceledException('Pool purge canceled.');
        $manager = Double::for(RedisManager::class)->strict();
        $manager->expects('discardConnections')->throws(new RuntimeException('Manager discard failed.'));
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws($cancellation);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(true);
        $container->expects('make')->with('redis')->returns($manager);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new RedisConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected the cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testPoolPurgeFailurePropagatesAfterManagerCleanup(): void
    {
        $exception = new RuntimeException('Pool purge failed.');
        $manager = Double::for(RedisManager::class)->strict();
        $manager->expects('discardConnections');
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws($exception);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('redis')->returns(true);
        $container->expects('make')->with('redis')->returns($manager);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new RedisConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected the pool purge failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }
}
