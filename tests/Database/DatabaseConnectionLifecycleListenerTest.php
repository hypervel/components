<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Listeners\DatabaseConnectionLifecycleListener;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\SimpleConnectionResolver;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class DatabaseConnectionLifecycleListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testTaskCleanupDoesNotResolveAnUnusedResolver(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(false);
        $container->expects('make')->never();

        (new DatabaseConnectionLifecycleListener($container->instance()))->releaseTaskConnections();
    }

    public function testTaskCleanupReleasesTheConcretePooledResolver(): void
    {
        $resolver = Double::for(ConnectionResolver::class)->strict();
        $resolver->expects('releaseConnections');
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);

        (new DatabaseConnectionLifecycleListener($container->instance()))->releaseTaskConnections();
    }

    public function testTaskCleanupLeavesCustomResolversAlone(): void
    {
        $resolver = Double::for(SimpleConnectionResolver::class)->strict();
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);

        (new DatabaseConnectionLifecycleListener($container->instance()))->releaseTaskConnections();
    }

    public function testProcessCleanupDoesNotResolveUnusedOwners(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(false);
        $container->expects('resolved')->with(PoolManager::class)->returns(false);
        $container->expects('make')->never();

        (new DatabaseConnectionLifecycleListener($container->instance()))->discardProcessConnections();
    }

    public function testProcessCleanupDiscardsResolverAndPurgesPools(): void
    {
        $resolver = Double::for(ConnectionResolver::class)->strict();
        $resolver->expects('discardConnections');
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll');
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        (new DatabaseConnectionLifecycleListener($container->instance()))->discardProcessConnections();
    }

    public function testResolverFailureDoesNotSkipPoolPurgeAndRemainsPrimary(): void
    {
        $resolverException = new RuntimeException('Resolver discard failed.');
        $purgeException = new RuntimeException('Pool purge failed.');
        $resolver = Double::for(ConnectionResolver::class)->strict();
        $resolver->expects('discardConnections')->throws($resolverException);
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws($purgeException);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected the resolver failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($resolverException, $throwable);
        }
    }

    public function testPoolPurgeFailurePropagatesAfterResolverCleanup(): void
    {
        $exception = new RuntimeException('Pool purge failed.');
        $resolver = Double::for(ConnectionResolver::class)->strict();
        $resolver->expects('discardConnections');
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws($exception);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected the pool purge failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPoolPurgeCancellationSupersedesAnOrdinaryResolverFailure(): void
    {
        $resolverException = new RuntimeException('Resolver discard failed.');
        $purgeCancellation = new CanceledException('Pool purge was canceled.');
        $resolver = Double::for(ConnectionResolver::class)->strict();
        $resolver->expects('discardConnections')->throws($resolverException);
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws($purgeCancellation);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected pool purge cancellation to propagate.');
        } catch (CanceledException $throwable) {
            $this->assertSame($purgeCancellation, $throwable);
        }
    }

    public function testResolverCancellationRemainsPrimaryOverAnOrdinaryPoolPurgeFailure(): void
    {
        $resolverCancellation = new CanceledException('Resolver discard was canceled.');
        $purgeException = new RuntimeException('Pool purge failed.');
        $resolver = Double::for(ConnectionResolver::class)->strict();
        $resolver->expects('discardConnections')->throws($resolverCancellation);
        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('purgeAll')->throws($purgeException);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('resolved')->with('db.resolver')->returns(true);
        $container->expects('make')->with('db.resolver')->returns($resolver);
        $container->expects('resolved')->with(PoolManager::class)->returns(true);
        $container->expects('make')->with(PoolManager::class)->returns($poolManager);

        try {
            (new DatabaseConnectionLifecycleListener($container->instance()))->discardProcessConnections();
            $this->fail('Expected resolver cancellation to propagate.');
        } catch (CanceledException $throwable) {
            $this->assertSame($resolverCancellation, $throwable);
        }
    }
}
