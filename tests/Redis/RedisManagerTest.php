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
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

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
        $withEmptyString = $manager->connection('');

        $this->assertSame($withNull, $withoutArg);
        $this->assertSame($withNull, $withEmptyString);
        $this->assertSame('default', $withNull->getName());
    }

    public function testIntegerBackedEnumConnectionNameIsNormalizedForResolutionAndPurge(): void
    {
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('flushPool')->once()->with('0');

        $manager = $this->createManager(['0'], poolFactory: $poolFactory);
        $connection = $manager->connection(RedisConnectionName::Zero);

        $this->assertSame('0', $connection->getName());
        $this->assertSame($connection, $manager->connections()['0']);

        $manager->purge(RedisConnectionName::Zero);

        $this->assertSame([], $manager->connections());
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . '0'));
    }

    public function testPurgeClearsProxyContextAndPool()
    {
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('flushPool')->once()->with('default');

        $manager = $this->createManager(['default'], poolFactory: $poolFactory);

        $first = $manager->connection('default');

        $manager->purge('');

        // Context should be cleared
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

        // Next connection() should return a new instance
        $second = $manager->connection('default');
        $this->assertNotSame($first, $second);
    }

    public function testPurgeDiscardsContextPinnedConnection(): void
    {
        $pinnedConnection = m::mock(PhpRedisConnection::class);
        $pinnedConnection->expects('discard');

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->expects('flushPool')->with('default');
        $manager = $this->createManager(['default'], poolFactory: $poolFactory);
        $manager->connection('default');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $pinnedConnection);

        $manager->purge('default');

        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testReleaseConnectionsExhaustsProxiesAndPreservesFirstFailure(): void
    {
        $firstException = new RuntimeException('First release failed.');
        $first = m::mock(PhpRedisConnection::class);
        $first->expects('release')->andThrow($firstException);
        $second = m::mock(PhpRedisConnection::class);
        $second->expects('release');
        $manager = $this->createManager(['first', 'second']);
        $manager->connection('first');
        $manager->connection('second');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'first', $first);
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'second', $second);

        try {
            $manager->releaseConnections();
            $this->fail('Expected the first release failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($firstException, $throwable);
        }
    }

    public function testDiscardConnectionsExhaustsEveryCreatedProxy(): void
    {
        $first = m::mock(PhpRedisConnection::class);
        $first->expects('discard');
        $second = m::mock(PhpRedisConnection::class);
        $second->expects('discard');
        $manager = $this->createManager(['first', 'second']);
        $manager->connection('first');
        $manager->connection('second');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'first', $first);
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'second', $second);

        $manager->discardConnections();
    }

    public function testPurgeFlushesPoolAfterDiscardFailureAndPreservesFirstFailure(): void
    {
        $discardException = new RuntimeException('Discard failed.');
        $connection = m::mock(PhpRedisConnection::class);
        $connection->expects('discard')->andThrow($discardException);
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->expects('flushPool')
            ->with('alias')
            ->andThrow(new RuntimeException('Flush failed.'));
        $manager = $this->createManager(['alias'], poolFactory: $poolFactory);
        $manager->connection('alias');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'alias', $connection);

        try {
            $manager->purge('alias');
            $this->fail('Expected the discard failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($discardException, $throwable);
        }

        $this->assertSame([], $manager->connections());
    }

    public function testConnectorDriverExtensionsAreIntentionallyUnavailable(): void
    {
        // REMOVED: Hypervel has one phpredis pooled transport rather than switchable connector drivers.
        $manager = $this->createManager(['default']);

        $this->assertFalse(method_exists($manager, 'extend'));
        $this->assertFalse(method_exists($manager, 'forgetExtension'));
        $this->assertFalse(method_exists($manager, 'setDriver'));
    }

    public function testEnableEventsDelegatesToRedisConfigWithoutTouchingPools(): void
    {
        $app = m::mock(ContainerContract::class);
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->expects('getPool')->never();
        $config = m::mock(RedisConfig::class);
        $config->expects('enableEvents');
        $manager = new RedisManager(
            $app,
            $poolFactory,
            $config,
            m::mock(RedisSentinelFactory::class),
        );

        $manager->enableEvents();
    }

    public function testDisableEventsDelegatesToRedisConfigWithoutTouchingPools(): void
    {
        $app = m::mock(ContainerContract::class);
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->expects('getPool')->never();
        $config = m::mock(RedisConfig::class);
        $config->expects('disableEvents');
        $manager = new RedisManager(
            $app,
            $poolFactory,
            $config,
            m::mock(RedisSentinelFactory::class),
        );

        $manager->disableEvents();
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
            $this->createRedisConfig(['default']),
            m::mock(RedisSentinelFactory::class),
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
            $this->createRedisConfig(['default']),
            m::mock(RedisSentinelFactory::class),
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

        return new RedisManager(
            $app,
            $poolFactory,
            $config,
            m::mock(RedisSentinelFactory::class),
        );
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

enum RedisConnectionName: int
{
    case Zero = 0;
}
