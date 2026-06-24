<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\RedisPoolHeartbeatTest;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Engine\Coroutine;
use Hypervel\Pool\Connection as BaseConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;
use RedisCluster;
use ReflectionProperty;
use RuntimeException;

use function Hypervel\Coroutine\run;

class RedisPoolHeartbeatTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    /**
     * @var InspectableRedisPool[]
     */
    protected array $pools = [];

    protected function tearDown(): void
    {
        foreach ($this->pools as $pool) {
            run(fn () => $pool->flushAll());
        }

        parent::tearDown();
    }

    public function testDisabledHeartbeatDoesNotStartTimer(): void
    {
        run(function () {
            $pool = $this->createPool([
                'heartbeat' => -1,
            ]);

            $this->assertSame(0, $pool->heartbeatTimerClosureCount());
        });
    }

    public function testEnabledHeartbeatStartsTimerAndFlushAllClearsIt(): void
    {
        run(function () {
            $pool = $this->createPool([
                'heartbeat' => 0.001,
            ]);

            $this->assertSame(1, $pool->heartbeatTimerClosureCount());

            $pool->flushAll();

            $this->assertSame(0, $pool->heartbeatTimerClosureCount());
        });
    }

    public function testHeartbeatKeepsMinimumConnectionsWarmAndEvictsExpiredExtras(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 3,
                'heartbeat' => -1,
                'max_idle_time' => 1.0,
            ]);

            $connections = [
                $pool->get(),
                $pool->get(),
                $pool->get(),
            ];

            foreach ($connections as $connection) {
                $connection->release();
                $this->ageReleasedConnection($connection);
            }

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(1, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatDiscardsLifetimeExpiredIdleConnectionBeforeCheckingHeartbeat(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);

            $connection->release();
            $this->ageConnectionGeneration($connection);

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $connection->heartbeatChecks);
            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatDoesNotRecycleBorrowedLifetimeExpiredConnection(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);

            $client = $connection->nativeClientForTest();
            $this->ageConnectionGeneration($connection);

            $pool->runHeartbeatForTest();

            $connection->getConnection();

            $this->assertSame($client, $connection->nativeClientForTest());
            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());

            $connection->release();
        });
    }

    public function testMaxLifetimeDisabledDoesNotRecycleAgedConnectionGeneration(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => -1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);
            $client = $connection->nativeClientForTest();

            $connection->release();
            $this->ageConnectionGeneration($connection);

            $nextConnection = $pool->get();
            $nextConnection->getConnection();

            $this->assertSame($connection, $nextConnection);
            $this->assertSame($client, $connection->nativeClientForTest());
            $this->assertSame(1, $connection->reconnectCount);

            $nextConnection->release();
        });
    }

    public function testHeartbeatRefreshedIdleConnectionIsReused(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_idle_time' => 1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);
            $client = $connection->nativeClientForTest();

            $connection->release();
            $this->ageReleaseTimeButKeepLastUseFresh($connection);

            $nextConnection = $pool->get();
            $nextConnection->getConnection();

            $this->assertSame($connection, $nextConnection);
            $this->assertSame($client, $connection->nativeClientForTest());
            $this->assertSame(1, $connection->reconnectCount);

            $nextConnection->release();
        });
    }

    public function testLifetimeExpiredConnectionReconnectsBeforeReuse(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);
            $client = $connection->nativeClientForTest();

            $connection->release();
            $this->ageConnectionGeneration($connection);

            $nextConnection = $pool->get();
            $nextConnection->getConnection();

            $this->assertSame($connection, $nextConnection);
            $this->assertNotSame($client, $connection->nativeClientForTest());
            $this->assertSame(2, $connection->reconnectCount);

            $nextConnection->release();
        });
    }

    public function testFailedHeartbeatCheckDiscardsConnectionBelowMinimum(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ], FailingHeartbeatRedisPool::class);

            $connection = $pool->get();
            $connection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatTimeoutDiscardsWithoutRequeueingLateCompletion(): void
    {
        run(function () {
            SlowHeartbeatRedisConnection::$coroutineId = null;

            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'heartbeat_timeout' => 0.001,
            ], SlowHeartbeatRedisPool::class);

            $connection = $pool->get();
            $connection->release();

            $startedAt = microtime(true);
            $pool->runHeartbeatForTest();
            $elapsed = microtime(true) - $startedAt;

            $this->assertLessThan(0.2, $elapsed);
            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
            $this->assertIsInt(SlowHeartbeatRedisConnection::$coroutineId);

            $deadline = microtime(true) + 0.1;
            while (Coroutine::exists(SlowHeartbeatRedisConnection::$coroutineId) && microtime(true) < $deadline) {
                usleep(1000);
            }

            $this->assertFalse(Coroutine::exists(SlowHeartbeatRedisConnection::$coroutineId));

            usleep(100000);

            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testSuccessfulHeartbeatCheckAfterFlushDiscardsConnection(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ], FlushingHeartbeatRedisPool::class);

            $connection = $pool->get();
            $connection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testReleaseResetFailureReturnsInvalidConnectionToPool(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);

            $redis = m::mock(Redis::class);
            $redis->shouldReceive('select')->once()->with(0)->andThrow(new RuntimeException('select failed'));
            $connection->setNativeClientForTest($redis);
            $connection->setDatabase(2);

            $connection->release();

            $this->assertNull((new ReflectionProperty(RedisConnection::class, 'database'))->getValue($connection));
            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(1, $pool->getConnectionsInChannel());

            $nextConnection = $pool->get();
            $nextConnection->getConnection();

            $this->assertSame($connection, $nextConnection);
            $this->assertSame(2, $connection->reconnectCount);

            $nextConnection->release();
        });
    }

    public function testWithConnectionReconnectsExpiredGenerationBeforeCallback(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);
            $client = $connection->nativeClientForTest();
            $connection->release();
            $this->ageConnectionGeneration($connection);

            $redis = $this->createProxy($pool);

            $redis->withConnection(function (RedisConnection $heldConnection) use ($connection, $client) {
                $this->assertSame($connection, $heldConnection);
                $this->assertNotSame($client, $connection->nativeClientForTest());
                $this->assertSame(2, $connection->reconnectCount);
            });
        });
    }

    public function testPinnedConnectionDoesNotRecycleExpiredGenerationMidBorrow(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(HeartbeatRedisConnection::class, $connection);
            $connection->release();
            $this->ageConnectionGeneration($connection);

            $redis = $this->createProxy($pool);

            $redis->withPinnedConnection(function () use ($redis, $connection) {
                $contextConnection = CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'heartbeat_test');

                $this->assertSame($connection, $contextConnection);
                $this->assertSame(2, $connection->reconnectCount);

                $client = $connection->nativeClientForTest();
                $this->ageConnectionGeneration($connection);

                $redis->get('first');
                $redis->get('second');

                $this->assertSame(2, $connection->reconnectCount);
                $this->assertSame($client, $connection->nativeClientForTest());
            });
        });
    }

    public function testClusterHeartbeatChecksAllMasters(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ], ClusterHeartbeatRedisPool::class, [
                'cluster' => [
                    'enable' => true,
                    'seeds' => ['127.0.0.1:6379'],
                ],
            ]);

            $connection = $pool->get();
            $this->assertInstanceOf(ClusterHeartbeatRedisConnection::class, $connection);
            $connection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame([
                ['127.0.0.1', 6379],
                ['127.0.0.2', 6379],
            ], $connection->clusterClient->pingedMasters);
            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(1, $pool->getConnectionsInChannel());
        });
    }

    /**
     * @param array<string, mixed> $poolOptions
     * @param array<string, mixed> $config
     */
    protected function createPool(array $poolOptions = [], string $poolClass = InspectableRedisPool::class, array $config = []): InspectableRedisPool
    {
        $connectionConfig = array_replace_recursive([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'cluster' => ['enable' => false],
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => -1.0,
                ...$poolOptions,
            ],
        ], $config);

        $container = new Container;
        $redisConfig = m::mock(RedisConfig::class);
        $redisConfig->shouldReceive('connectionConfig')->once()->with('heartbeat_test')->andReturn($connectionConfig);
        $container->instance(RedisConfig::class, $redisConfig);

        $pool = new $poolClass($container, 'heartbeat_test');
        $this->pools[] = $pool;

        return $pool;
    }

    protected function createProxy(RedisPool $pool): RedisProxy
    {
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('heartbeat_test')->andReturn($pool);

        return new RedisProxy($poolFactory, 'heartbeat_test');
    }

    protected function ageReleasedConnection(RedisConnection $connection): void
    {
        (new ReflectionProperty(BaseConnection::class, 'lastReleaseTime'))->setValue($connection, microtime(true) - 5.0);
        (new ReflectionProperty(BaseConnection::class, 'lastUseTime'))->setValue($connection, microtime(true) - 5.0);
    }

    protected function ageConnectionGeneration(RedisConnection $connection): void
    {
        (new ReflectionProperty(RedisConnection::class, 'createdAt'))->setValue($connection, microtime(true) - 5.0);
    }

    protected function ageReleaseTimeButKeepLastUseFresh(RedisConnection $connection): void
    {
        (new ReflectionProperty(BaseConnection::class, 'lastReleaseTime'))->setValue($connection, microtime(true) - 5.0);
        (new ReflectionProperty(BaseConnection::class, 'lastUseTime'))->setValue($connection, microtime(true));
    }
}

class InspectableRedisPool extends RedisPool
{
    public function runHeartbeatForTest(): void
    {
        $this->heartbeat();
    }

    public function heartbeatTimerClosureCount(): int
    {
        $timer = (new ReflectionProperty(RedisPool::class, 'heartbeatTimer'))->getValue($this);

        return $timer === null ? 0 : count((new ClassInvoker($timer))->closures);
    }

    protected function createConnection(): ConnectionInterface
    {
        return new HeartbeatRedisConnection($this->container, $this, $this->config);
    }
}

class HeartbeatRedisConnection extends RedisConnection
{
    public int $reconnectCount = 0;

    public int $heartbeatChecks = 0;

    public bool $heartbeatResult = true;

    public bool $useNativeHeartbeat = false;

    public function __construct(Container $container, PoolInterface $pool, array $config)
    {
        parent::__construct($container, $pool, $config);

        $this->reconnect();
    }

    public function reconnect(): bool
    {
        $this->connection = m::mock(Redis::class)->shouldIgnoreMissing();
        ++$this->reconnectCount;
        $this->markReconnected();

        return true;
    }

    public function setNativeClientForTest(Redis $redis): void
    {
        $this->connection = $redis;
    }

    public function nativeClientForTest(): Redis
    {
        $this->getConnection();

        $this->assertNativeClientForTest();

        return $this->connection;
    }

    protected function pingForHeartbeat(): bool
    {
        if ($this->useNativeHeartbeat) {
            return parent::pingForHeartbeat();
        }

        ++$this->heartbeatChecks;

        return $this->heartbeatResult;
    }

    private function assertNativeClientForTest(): void
    {
        if (! $this->connection instanceof Redis) {
            throw new RuntimeException('Expected native Redis client.');
        }
    }
}

class FailingHeartbeatRedisPool extends InspectableRedisPool
{
    protected function createConnection(): ConnectionInterface
    {
        $connection = new HeartbeatRedisConnection($this->container, $this, $this->config);
        $connection->heartbeatResult = false;

        return $connection;
    }
}

class SlowHeartbeatRedisPool extends InspectableRedisPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new SlowHeartbeatRedisConnection($this->container, $this, $this->config);
    }
}

class SlowHeartbeatRedisConnection extends HeartbeatRedisConnection
{
    public static ?int $coroutineId = null;

    protected function pingForHeartbeat(): bool
    {
        self::$coroutineId = Coroutine::id();

        usleep(500000);

        return true;
    }
}

class FlushingHeartbeatRedisPool extends InspectableRedisPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new FlushingHeartbeatRedisConnection($this->container, $this, $this->config);
    }
}

class FlushingHeartbeatRedisConnection extends HeartbeatRedisConnection
{
    protected function pingForHeartbeat(): bool
    {
        $this->pool->flushAll();

        return true;
    }
}

class ClusterHeartbeatRedisPool extends InspectableRedisPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new ClusterHeartbeatRedisConnection($this->container, $this, $this->config);
    }
}

class ClusterHeartbeatRedisConnection extends HeartbeatRedisConnection
{
    public TestHeartbeatRedisClusterClient $clusterClient;

    public function reconnect(): bool
    {
        $redis = new TestHeartbeatRedisClusterClient([
            ['127.0.0.1', 6379],
            ['127.0.0.2', 6379],
        ]);
        $this->clusterClient = $redis;
        $this->connection = $redis;
        $this->useNativeHeartbeat = true;
        ++$this->reconnectCount;
        $this->markReconnected();

        return true;
    }
}

class TestHeartbeatRedisClusterClient extends RedisCluster
{
    /**
     * @var array<int, array{0: string, 1: int}>
     */
    public array $pingedMasters = [];

    /**
     * @param array<int, array{0: string, 1: int}> $masters
     */
    public function __construct(private array $masters)
    {
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    public function _masters(): array
    {
        return $this->masters;
    }

    public function ping(array|string $key_or_address, ?string $message = null): mixed
    {
        if (is_array($key_or_address)) {
            $this->pingedMasters[] = $key_or_address;
        }

        return true;
    }

    public function close(): bool
    {
        return true;
    }
}
