<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis;

use Carbon\Carbon;
use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hyperf\Redis\RedisFactory as HyperfRedisFactory;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisFactory as HypervelRedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Redis\Stub\FakeRedisClient;
use Mockery as m;
use Redis;
use RedisCluster;

/**
 * Base test case for Redis cache unit tests.
 *
 * Provides:
 * - Mock connection helpers for standard and cluster modes
 * - Fixed test time for ZSET timestamp score calculations
 * - Automatic Mockery and Carbon cleanup (via Foundation\Testing\TestCase)
 *
 * ## Usage Examples
 *
 * ### Standard (non-cluster) tests:
 * ```php
 * $connection = $this->mockConnection();
 * $client = $connection->_mockClient;
 * $client->shouldReceive('set')->once()->andReturn(true);
 *
 * $store = $this->createStore($connection);
 * // or with tag mode:
 * $store = $this->createStore($connection, tagMode: 'any');
 * ```
 *
 * ### Cluster mode tests:
 * ```php
 * [$store, $clusterClient] = $this->createClusterStore();
 * $clusterClient->shouldNotReceive('pipeline');
 * $clusterClient->shouldReceive('set')->once()->andReturn(true);
 * ```
 *
 * @internal
 * @coversNothing
 */
abstract class RedisCacheTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fixed time for tag tests - ZSET scores use timestamps
        Carbon::setTestNow('2000-01-01 00:00:00');
    }

    /**
     * Create a mock RedisConnection with standard expectations.
     *
     * By default creates a mock with a standard Redis client (not cluster).
     * Use createClusterStore() for cluster mode tests.
     *
     * We use an anonymous mock for the client (not m::mock(Redis::class))
     * because mocking the native phpredis extension class can cause
     * unexpected fallthrough to real Redis connections when expectations
     * don't match.
     *
     * @return m\MockInterface|RedisConnection connection with _mockClient property for setting expectations
     */
    protected function mockConnection(): m\MockInterface|RedisConnection
    {
        // Anonymous mock - not bound to Redis extension class
        // This prevents fallthrough to real Redis when expectations don't match
        $client = m::mock();
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE)
            ->byDefault();
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('')
            ->byDefault();

        // Default pipeline() returns self for chaining (can be overridden in tests)
        $client->shouldReceive('pipeline')->andReturn($client)->byDefault();
        $client->shouldReceive('exec')->andReturn([])->byDefault();

        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('release')->zeroOrMoreTimes();
        $connection->shouldReceive('serialized')->andReturn(false)->byDefault();
        $connection->shouldReceive('client')->andReturn($client)->byDefault();

        // Store client reference for tests that need to set expectations on it
        $connection->_mockClient = $client;

        return $connection;
    }

    /**
     * Create a mock RedisConnection configured as a cluster connection.
     *
     * The client mock is configured to pass instanceof RedisCluster checks
     * which triggers cluster mode (sequential commands instead of pipelines).
     *
     * @return m\MockInterface|RedisConnection connection with _mockClient property for setting expectations
     */
    protected function mockClusterConnection(): m\MockInterface|RedisConnection
    {
        // Mock that identifies as RedisCluster for instanceof checks
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE)
            ->byDefault();
        $client->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('')
            ->byDefault();

        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('release')->zeroOrMoreTimes();
        $connection->shouldReceive('serialized')->andReturn(false)->byDefault();
        $connection->shouldReceive('client')->andReturn($client)->byDefault();

        // Store client reference for tests that need to set expectations on it
        $connection->_mockClient = $client;

        return $connection;
    }

    /**
     * Create a PoolFactory mock that returns the given connection.
     */
    protected function createPoolFactory(
        m\MockInterface|RedisConnection $connection,
        string $connectionName = 'default'
    ): m\MockInterface|PoolFactory {
        $poolFactory = m::mock(PoolFactory::class);
        $pool = m::mock(RedisPool::class);

        $poolFactory->shouldReceive('getPool')
            ->with($connectionName)
            ->andReturn($pool);

        $pool->shouldReceive('get')->andReturn($connection);

        return $poolFactory;
    }

    /**
     * Register a RedisFactory mock in the container.
     *
     * This sets up the mock that StoreContext::withConnection() uses to get
     * connections via ApplicationContext::getContainer().
     */
    protected function registerRedisFactoryMock(
        m\MockInterface|RedisConnection $connection,
        string $connectionName = 'default'
    ): void {
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('withConnection')
            ->andReturnUsing(fn (callable $callback) => $callback($connection));

        $redisFactory = m::mock(HypervelRedisFactory::class);
        $redisFactory->shouldReceive('get')
            ->with($connectionName)
            ->andReturn($redisProxy);

        $this->instance(HypervelRedisFactory::class, $redisFactory);
    }

    /**
     * Create a RedisStore with a mocked connection.
     *
     * @param m\MockInterface|RedisConnection $connection the mocked connection (from mockConnection())
     * @param string $prefix cache key prefix
     * @param string $connectionName Redis connection name
     * @param null|string $tagMode optional tag mode ('any' or 'all'). If provided, setTagMode() is called.
     */
    protected function createStore(
        m\MockInterface|RedisConnection $connection,
        string $prefix = 'prefix:',
        string $connectionName = 'default',
        ?string $tagMode = null,
    ): RedisStore {
        // Register RedisFactory mock for StoreContext::withConnection()
        $this->registerRedisFactoryMock($connection, $connectionName);

        $store = new RedisStore(
            m::mock(HyperfRedisFactory::class),
            $prefix,
            $connectionName,
            $this->createPoolFactory($connection, $connectionName)
        );

        if ($tagMode !== null) {
            $store->setTagMode($tagMode);
        }

        return $store;
    }

    /**
     * Create a RedisStore configured for cluster mode testing.
     *
     * This eliminates the boilerplate of manually setting up RedisCluster mocks,
     * connection mocks, pool mocks, and pool factory mocks for each cluster test.
     *
     * Returns the store, cluster client mock, and connection mock so tests can set expectations:
     * ```php
     * [$store, $clusterClient, $connection] = $this->createClusterStore();
     * $clusterClient->shouldNotReceive('pipeline');
     * $clusterClient->shouldReceive('zadd')->once()->andReturn(1);
     * $connection->shouldReceive('del')->once()->andReturn(1); // connection-level operations
     * ```
     *
     * @param string $prefix cache key prefix
     * @param string $connectionName Redis connection name
     * @param null|string $tagMode optional tag mode ('any' or 'all')
     * @return array{0: RedisStore, 1: m\MockInterface, 2: m\MockInterface} [store, clusterClient, connection]
     */
    protected function createClusterStore(
        string $prefix = 'prefix:',
        string $connectionName = 'default',
        ?string $tagMode = null,
    ): array {
        $connection = $this->mockClusterConnection();
        $clusterClient = $connection->_mockClient;

        // Register RedisFactory mock for StoreContext::withConnection()
        $this->registerRedisFactoryMock($connection, $connectionName);

        $store = new RedisStore(
            m::mock(HyperfRedisFactory::class),
            $prefix,
            $connectionName,
            $this->createPoolFactory($connection, $connectionName)
        );

        if ($tagMode !== null) {
            $store->setTagMode($tagMode);
        }

        return [$store, $clusterClient, $connection];
    }

    /**
     * Create a RedisStore with a FakeRedisClient.
     *
     * Use this for tests that need proper reference parameter handling (e.g., &$iterator
     * in SCAN/HSCAN/ZSCAN operations) which Mockery cannot properly propagate.
     *
     * @param FakeRedisClient $fakeClient pre-configured fake client with expected responses
     * @param string $prefix cache key prefix
     * @param string $connectionName Redis connection name
     * @param null|string $tagMode optional tag mode ('any' or 'all')
     */
    protected function createStoreWithFakeClient(
        FakeRedisClient $fakeClient,
        string $prefix = 'prefix:',
        string $connectionName = 'default',
        ?string $tagMode = null,
    ): RedisStore {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('release')->zeroOrMoreTimes();
        $connection->shouldReceive('serialized')->andReturn(false)->byDefault();
        $connection->shouldReceive('client')->andReturn($fakeClient)->byDefault();

        // Register RedisFactory mock for StoreContext::withConnection()
        $this->registerRedisFactoryMock($connection, $connectionName);

        $store = new RedisStore(
            m::mock(HyperfRedisFactory::class),
            $prefix,
            $connectionName,
            $this->createPoolFactory($connection, $connectionName)
        );

        if ($tagMode !== null) {
            $store->setTagMode($tagMode);
        }

        return $store;
    }
}
