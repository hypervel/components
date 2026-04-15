<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Config\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

/**
 * Tests for RedisManager — the named connection manager.
 */
class RedisManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        CoroutineContext::forget(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default');
    }

    public function testConnectionReturnsRedisProxy()
    {
        $manager = $this->createManager(['default']);

        $connection = $manager->connection('default');

        $this->assertInstanceOf(RedisProxy::class, $connection);
    }

    public function testConnectionReturnsSameInstanceOnRepeatedCalls()
    {
        $manager = $this->createManager(['default']);

        $first = $manager->connection('default');
        $second = $manager->connection('default');

        $this->assertSame($first, $second);
    }

    public function testConnectionThrowsForUnconfiguredConnection()
    {
        $manager = $this->createManager(['default']);

        $this->expectException(InvalidArgumentException::class);

        $manager->connection('nonexistent');
    }

    public function testConnectionDefaultsToDefault()
    {
        $manager = $this->createManager(['default']);

        $withNull = $manager->connection(null);
        $withoutArg = $manager->connection();

        $this->assertSame($withNull, $withoutArg);
        $this->assertSame('default', $withNull->getName());
    }

    public function testPurgeClearsProxyContextAndPool()
    {
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('flushPool')->once()->with('default');

        $manager = $this->createManager(['default'], poolFactory: $poolFactory);

        $first = $manager->connection('default');

        $manager->purge('default');

        // Context should be cleared
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

        // Next connection() should return a new instance
        $second = $manager->connection('default');
        $this->assertNotSame($first, $second);
    }

    public function testPurgeReleasesContextPinnedConnection()
    {
        $pinnedConnection = m::mock(PhpRedisConnection::class);
        $pinnedConnection->shouldReceive('release')->once();

        // Pin a connection in context (simulating an active multi/pipeline)
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $pinnedConnection);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('flushPool')->with('default');

        $manager = $this->createManager(['default'], poolFactory: $poolFactory);

        $manager->purge('default');

        // Context should be cleared after release
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testExtendOverridesConnectionResolution()
    {
        $manager = $this->createManager(['default']);

        $custom = m::mock(RedisProxy::class);

        $manager->extend('custom', function ($app, $name) use ($custom) {
            return $custom;
        });

        $this->assertSame($custom, $manager->connection('custom'));
    }

    public function testExtendDoesNotAffectOtherConnections()
    {
        $manager = $this->createManager(['default']);

        $custom = m::mock(RedisProxy::class);

        $manager->extend('custom', function () use ($custom) {
            return $custom;
        });

        $default = $manager->connection('default');

        $this->assertInstanceOf(RedisProxy::class, $default);
        $this->assertNotSame($custom, $default);
    }

    public function testExtendInvalidatesCachedConnection()
    {
        $manager = $this->createManager(['default']);

        // Resolve default first — caches a normal proxy
        $original = $manager->connection('default');

        // Now extend default — should invalidate the cached proxy
        $custom = m::mock(RedisProxy::class);
        $manager->extend('default', function () use ($custom) {
            return $custom;
        });

        // Next connection() should return the custom one, not the cached original
        $this->assertSame($custom, $manager->connection('default'));
        $this->assertNotSame($original, $manager->connection('default'));
    }

    public function testForgetExtensionRemovesCustomResolver()
    {
        $manager = $this->createManager(['default']);

        $custom = m::mock(RedisProxy::class);
        $manager->extend('default', function () use ($custom) {
            return $custom;
        });

        $this->assertSame($custom, $manager->connection('default'));

        $manager->forgetExtension('default');

        // Should go through normal resolution now
        $result = $manager->connection('default');
        $this->assertNotSame($custom, $result);
        $this->assertInstanceOf(RedisProxy::class, $result);
    }

    public function testForgetExtensionInvalidatesCachedConnection()
    {
        $manager = $this->createManager(['default']);

        // Extend, resolve (caches the custom proxy)
        $custom = m::mock(RedisProxy::class);
        $manager->extend('default', function () use ($custom) {
            return $custom;
        });
        $this->assertSame($custom, $manager->connection('default'));

        // Forget — should invalidate the cached custom proxy
        $manager->forgetExtension('default');

        // Next connection() should return a normal proxy
        $result = $manager->connection('default');
        $this->assertNotSame($custom, $result);
        $this->assertInstanceOf(RedisProxy::class, $result);
    }

    public function testCallDelegatesToDefaultConnection()
    {
        $manager = $this->createManager(['default']);

        // connection() returns a RedisProxy. We can verify __call delegation
        // by checking that the proxy's getName() is returned via the manager.
        $this->assertSame('default', $manager->getName());
    }

    public function testListenRegistersCommandExecutedListener()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(CommandExecuted::class, m::type('Closure'));

        $app = m::mock(ContainerContract::class);
        $app->shouldReceive('bound')->with('events')->andReturn(true);
        $app->shouldReceive('make')->with('events')->andReturn($dispatcher);

        $manager = new RedisManager(
            $app,
            m::mock(PoolFactory::class),
            $this->createRedisConfig(['default'])
        );

        $manager->listen(function () {});
    }

    public function testListenForFailuresRegistersCommandFailedListener()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->once()
            ->with(CommandFailed::class, m::type('Closure'));

        $app = m::mock(ContainerContract::class);
        $app->shouldReceive('bound')->with('events')->andReturn(true);
        $app->shouldReceive('make')->with('events')->andReturn($dispatcher);

        $manager = new RedisManager(
            $app,
            m::mock(PoolFactory::class),
            $this->createRedisConfig(['default'])
        );

        $manager->listenForFailures(function () {});
    }

    public function testConnectionsReturnsAllCachedProxies()
    {
        $manager = $this->createManager(['default', 'cache']);

        $this->assertEmpty($manager->connections());

        $default = $manager->connection('default');
        $cache = $manager->connection('cache');

        $connections = $manager->connections();

        $this->assertCount(2, $connections);
        $this->assertSame($default, $connections['default']);
        $this->assertSame($cache, $connections['cache']);
    }

    /**
     * Create a RedisManager with mocked dependencies.
     *
     * @param list<string> $configuredConnections Connection names that config considers valid
     */
    private function createManager(
        array $configuredConnections,
        ?PoolFactory $poolFactory = null
    ): RedisManager {
        $app = m::mock(ContainerContract::class);
        $poolFactory ??= m::mock(PoolFactory::class);
        $config = $this->createRedisConfig($configuredConnections);

        return new RedisManager($app, $poolFactory, $config);
    }

    /**
     * Create a RedisConfig mock that validates connection names.
     *
     * @param list<string> $validNames
     */
    private function createRedisConfig(array $validNames): RedisConfig
    {
        $configData = [];
        foreach ($validNames as $name) {
            $configData[$name] = [
                'host' => 'localhost',
                'port' => 6379,
                'database' => 0,
            ];
        }

        $repository = new Repository(['database' => ['redis' => $configData]]);

        return new RedisConfig($repository);
    }
}
