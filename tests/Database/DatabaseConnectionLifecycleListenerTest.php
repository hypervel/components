<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Listeners\DatabaseConnectionLifecycleListener;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\SimpleConnectionResolver;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class DatabaseConnectionLifecycleListenerTest extends TestCase
{
    public function testTaskCleanupDoesNotResolveAnUnusedResolver(): void
    {
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnFalse();
        $container->shouldNotReceive('make');

        (new DatabaseConnectionLifecycleListener($container))->releaseTaskConnections();
    }

    public function testTaskCleanupReleasesTheConcretePooledResolver(): void
    {
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('releaseConnections');
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);

        (new DatabaseConnectionLifecycleListener($container))->releaseTaskConnections();
    }

    public function testTaskCleanupLeavesCustomResolversAlone(): void
    {
        $resolver = m::mock(SimpleConnectionResolver::class);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);

        (new DatabaseConnectionLifecycleListener($container))->releaseTaskConnections();
    }

    public function testProcessCleanupDoesNotResolveUnusedOwners(): void
    {
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnFalse();
        $container->expects('resolved')->with(PoolManager::class)->andReturnFalse();
        $container->shouldNotReceive('make');

        (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testProcessCleanupDiscardsResolverAndPurgesPools(): void
    {
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections');
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll');
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testResolverFailureDoesNotSkipPoolPurgeAndRemainsPrimary(): void
    {
        $resolverException = new RuntimeException('Resolver discard failed.');
        $purgeException = new RuntimeException('Pool purge failed.');
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections')->andThrow($resolverException);
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow($purgeException);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the resolver failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($resolverException, $throwable);
        }
    }

    public function testPoolPurgeFailurePropagatesAfterResolverCleanup(): void
    {
        $exception = new RuntimeException('Pool purge failed.');
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections');
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow($exception);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the pool purge failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPoolPurgeCancellationSupersedesAnOrdinaryResolverFailure(): void
    {
        $resolverException = new RuntimeException('Resolver discard failed.');
        $purgeCancellation = new CanceledException('Pool purge was canceled.');
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections')->andThrow($resolverException);
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow($purgeCancellation);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected pool purge cancellation to propagate.');
        } catch (CanceledException $throwable) {
            $this->assertSame($purgeCancellation, $throwable);
        }
    }

    public function testResolverCancellationRemainsPrimaryOverAnOrdinaryPoolPurgeFailure(): void
    {
        $resolverCancellation = new CanceledException('Resolver discard was canceled.');
        $purgeException = new RuntimeException('Pool purge failed.');
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections')->andThrow($resolverCancellation);
        $poolManager = m::mock(PoolManager::class);
        $poolManager->expects('purgeAll')->andThrow($purgeException);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolManager::class)->andReturnTrue();
        $container->expects('make')->with(PoolManager::class)->andReturn($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected resolver cancellation to propagate.');
        } catch (CanceledException $throwable) {
            $this->assertSame($resolverCancellation, $throwable);
        }
    }
}
