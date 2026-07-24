<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Listeners\DatabaseConnectionLifecycleListener;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Database\SimpleConnectionResolver;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

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
        $container->expects('resolved')->with(PoolFactory::class)->andReturnFalse();
        $container->shouldNotReceive('make');

        (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testProcessCleanupDiscardsResolverAndFlushesPoolFactory(): void
    {
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections');
        $factory = m::mock(PoolFactory::class);
        $factory->expects('flushAll');
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolFactory::class)->andReturnTrue();
        $container->expects('make')->with(PoolFactory::class)->andReturn($factory);

        (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
    }

    public function testResolverFailureDoesNotSkipPoolFlushAndRemainsPrimary(): void
    {
        $resolverException = new RuntimeException('Resolver discard failed.');
        $factoryException = new RuntimeException('Pool flush failed.');
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections')->andThrow($resolverException);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('flushAll')->andThrow($factoryException);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolFactory::class)->andReturnTrue();
        $container->expects('make')->with(PoolFactory::class)->andReturn($factory);

        try {
            (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the resolver failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($resolverException, $throwable);
        }
    }

    public function testPoolFactoryFailurePropagatesAfterResolverCleanup(): void
    {
        $exception = new RuntimeException('Pool flush failed.');
        $resolver = m::mock(ConnectionResolver::class);
        $resolver->expects('discardConnections');
        $factory = m::mock(PoolFactory::class);
        $factory->expects('flushAll')->andThrow($exception);
        $container = m::mock(Container::class);
        $container->expects('resolved')->with('db.resolver')->andReturnTrue();
        $container->expects('make')->with('db.resolver')->andReturn($resolver);
        $container->expects('resolved')->with(PoolFactory::class)->andReturnTrue();
        $container->expects('make')->with(PoolFactory::class)->andReturn($factory);

        try {
            (new DatabaseConnectionLifecycleListener($container))->discardProcessConnections();
            $this->fail('Expected the pool factory failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }
}
