<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Pool\PoolOption;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Exception\ConnectionException;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Redis\Stubs\RedisConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LogLevel;
use Redis;
use RedisCluster;
use RedisException;
use RuntimeException;
use TypeError;

/**
 * @internal
 * @coversNothing
 */
class RedisConnectionTest extends TestCase
{
    public function testShouldTransform(): void
    {
        $connection = $this->mockRedisConnection();

        $this->assertFalse($connection->getShouldTransform());

        $connection->shouldTransform(true);

        $this->assertTrue($connection->getShouldTransform());
    }

    public function testRelease(): void
    {
        $pool = $this->getMockedPool();
        $pool->shouldReceive('release')->once();

        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->shouldTransform(true);

        $connection->release();

        $this->assertFalse($connection->getShouldTransform());
    }

    public function testReleaseResetsDatabaseToConfiguredDefault(): void
    {
        $pool = $this->getMockedPool();
        $pool->shouldReceive('release')->once();

        $redis = m::mock(Redis::class);
        $redis->shouldReceive('select')->once()->with(1)->andReturn(true);
        $redis->shouldReceive('select')->once()->with(1)->andReturn(true);

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->setDatabase(2);
        $connection->release();
    }

    public function testReleaseDefaultsToDatabaseZeroWhenDbConfigIsMissing(): void
    {
        $pool = $this->getMockedPool();
        $pool->shouldReceive('release')->once();

        $redis = m::mock(Redis::class);
        $redis->shouldReceive('select')->once()->with(0)->andReturn(true);

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->setDatabase(5);
        $connection->release();
    }

    public function testReconnectUsesCurrentDatabaseWhenSet(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('select')->once()->with(2)->andReturn(true);

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'db' => 0], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->setDatabase(2);
        $connection->reconnect();
    }

    public function testConnectionConfigMergesDefaults(): void
    {
        $connection = new RedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
            [
                'host' => 'redis',
                'port' => 16379,
                'auth' => 'redis',
                'db' => 0,
                'retry_interval' => 5,
                'read_timeout' => 3.0,
                'context' => [
                    'stream' => ['cafile' => 'foo-cafile', 'verify_peer' => true],
                ],
                'cluster' => [
                    'enable' => false,
                    'name' => null,
                    'seeds' => ['127.0.0.1:6379'],
                    'context' => [
                        'stream' => ['cafile' => 'foo-cafile', 'verify_peer' => true],
                    ],
                ],
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 30,
                    'connect_timeout' => 10.0,
                    'wait_timeout' => 3.0,
                    'heartbeat' => -1,
                    'max_idle_time' => 1,
                ],
            ],
        );

        $this->assertSame(
            [
                'timeout' => 0.0,
                'reserved' => null,
                'retry_interval' => 5,
                'read_timeout' => 3.0,
                'cluster' => [
                    'enable' => false,
                    'name' => null,
                    'seeds' => ['127.0.0.1:6379'],
                    'read_timeout' => 0.0,
                    'persistent' => false,
                    'context' => [
                        'stream' => ['cafile' => 'foo-cafile', 'verify_peer' => true],
                    ],
                ],
                'sentinel' => [
                    'enable' => false,
                    'master_name' => '',
                    'nodes' => [],
                    'persistent' => '',
                    'read_timeout' => 0,
                ],
                'options' => [],
                'context' => [
                    'stream' => ['cafile' => 'foo-cafile', 'verify_peer' => true],
                ],
                'event' => [
                    'enable' => false,
                ],
                'host' => 'redis',
                'port' => 16379,
                'auth' => 'redis',
                'db' => 0,
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 30,
                    'connect_timeout' => 10.0,
                    'wait_timeout' => 3.0,
                    'heartbeat' => -1,
                    'max_idle_time' => 1,
                ],
            ],
            $connection->getConfigForTest(),
        );
    }

    public function testClusterReconnectFailureThrowsConnectionException(): void
    {
        if (version_compare((string) phpversion('redis'), '6.0.0', '<')) {
            $this->markTestSkipped('Cluster constructor typing differs on redis extension < 6.');
        }

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection reconnect failed');

        new class($this->getContainer(), $this->getMockedPool(), ['cluster' => ['enable' => true, 'name' => 'mycluster', 'seeds' => [], 'read_timeout' => 1.0, 'persistent' => false], 'timeout' => 1.0]) extends RedisConnection {
            protected function createRedis(array $config): Redis
            {
                throw new RuntimeException('createRedis should not be called for cluster config.');
            }
        };
    }

    public function testQueueingModeBypassesTransformedSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('set')->once()->with('key', 'value', 600)->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertSame($redis, $result);
    }

    public function testPipelineModeBypassesTransformedSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::PIPELINE);
        $redis->shouldReceive('set')->once()->with('key', 'value', 600)->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertSame($redis, $result);
    }

    public function testTransformDisabledSetUsesNativeSignatureWithoutInspectingMode(): void
    {
        $connection = $this->mockRedisConnection(transform: false);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->never();
        $redis->shouldReceive('set')->once()->with('key', 'value', 600)->andReturn(true);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertTrue($result);
    }

    public function testTypeErrorsAreNotRetried(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::ATOMIC);
        $connection->setActiveConnection($redis);

        $this->expectException(TypeError::class);

        $connection->__call('set', ['key', 'value', 600]);
    }

    public function testRedisExceptionIsRetried(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('get')
            ->once()
            ->with('foo')
            ->andThrow(new RedisException('network'));
        $redis->shouldReceive('get')
            ->once()
            ->with('foo')
            ->andReturn('bar');

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->shouldTransform(false);

        $result = $connection->__call('get', ['foo']);

        $this->assertSame('bar', $result);
    }

    public function testLogWritesToStdoutLogger(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(LogLevel::ERROR, 'unit');

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->with(\Hypervel\Contracts\Event\Dispatcher::class)->andReturn(false);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn($logger);

        $connection = new class($container, $pool, ['host' => '127.0.0.1', 'port' => 6379], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }

            public function callLog(string $message, string $level): void
            {
                $this->log($message, $level);
            }
        };

        $connection->callLog('unit', LogLevel::ERROR);
    }

    public function testCallGet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('get')
            ->with($key = 'foo')
            ->once()
            ->andReturn($value = 'bar');

        $result = $connection->__call('get', [$key]);

        $this->assertEquals($value, $result);
    }

    public function testMget(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('mGet')
            ->with(['key1', 'key2', 'key3'])
            ->once()
            ->andReturn(['value1', false, 'value3']);

        $result = $connection->__call('mget', [['key1', 'key2', 'key3']]);

        $this->assertEquals(['value1', null, 'value3'], $result);
    }

    public function testSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('set')
            ->with('key', 'value', ['NX', 'EX' => 3600])
            ->once()
            ->andReturn(true);

        $result = $connection->__call('set', ['key', 'value', 'EX', 3600, 'NX']);

        $this->assertTrue($result);
    }

    public function testSetnx(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('setNx')
            ->with('key', 'value')
            ->once()
            ->andReturn(true);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertEquals(1, $result);
    }

    public function testHmgetSingleArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->__call('hmget', ['hash', ['field1', 'field2']]);

        $this->assertEquals(['value1', 'value2'], $result);
    }

    public function testHmgetMultipleArgs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->__call('hmget', ['hash', 'field1', 'field2']);

        $this->assertEquals(['value1', 'value2'], $result);
    }

    public function testHmset(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->once()
            ->andReturn(true);

        $result = $connection->__call('hmset', ['hash', ['field1' => 'value1', 'field2' => 'value2']]);

        $this->assertTrue($result);
    }

    public function testHsetnx(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hSetNx')
            ->with('hash', 'field', 'value')
            ->once()
            ->andReturn(true);

        $result = $connection->__call('hsetnx', ['hash', 'field', 'value']);

        $this->assertEquals(1, $result);
    }

    public function testLrem(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('lRem')
            ->with('list', 'value', 2)
            ->once()
            ->andReturn(1);

        $result = $connection->__call('lrem', ['list', 2, 'value']);

        $this->assertEquals(1, $result);
    }

    public function testBlpopWithResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('blPop')
            ->with('list1', 'list2', 10)
            ->once()
            ->andReturn(['list1', 'value']);

        $result = $connection->__call('blpop', ['list1', 'list2', 10]);

        $this->assertEquals(['list1', 'value'], $result);
    }

    public function testBlpopEmpty(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('blPop')
            ->with('list1', 10)
            ->once()
            ->andReturn([]);

        $result = $connection->__call('blpop', ['list1', 10]);

        $this->assertNull($result);
    }

    public function testBrpopWithResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('brPop')
            ->with('list1', 'list2', 10)
            ->once()
            ->andReturn(['list2', 'value']);

        $result = $connection->__call('brpop', ['list1', 'list2', 10]);

        $this->assertEquals(['list2', 'value'], $result);
    }

    public function testBrpopEmpty(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('brPop')
            ->with('list1', 10)
            ->once()
            ->andReturn([]);

        $result = $connection->__call('brpop', ['list1', 10]);

        $this->assertNull($result);
    }

    public function testSpop(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('sPop')
            ->with('myset', 2)
            ->once()
            ->andReturn(['member1', 'member2']);

        $result = $connection->__call('spop', ['myset', 2]);

        $this->assertEquals(['member1', 'member2'], $result);
    }

    public function testScan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, '*', 10)
            ->once()
            ->andReturn(['key1', 'key2']);

        $result = $connection->scan($cursor, '*', 10);

        $this->assertEquals([0, ['key1', 'key2']], $result);
    }

    public function testScanWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, 'prefix:*', 20)
            ->once()
            ->andReturn(['key1', 'key2']);

        $result = $connection->scan($cursor, 'prefix:*', 20);

        $this->assertEquals([0, ['key1', 'key2']], $result);
    }

    public function testScanWithEmptyResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, '*', 10)
            ->once()
            ->andReturn(false);

        $result = $connection->scan($cursor, '*', 10);

        $this->assertFalse($result);
    }

    public function testZscan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('zscan')
            ->with('sortedset', 0, '*', 10)
            ->once()
            ->andReturn(['member1' => 1.0, 'member2' => 2.0]);

        $result = $connection->zscan('sortedset', $cursor, '*', 10);

        $this->assertEquals([0, ['member1' => 1.0, 'member2' => 2.0]], $result);
    }

    public function testHscan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('hscan')
            ->with('hash', 0, '*', 10)
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->hscan('hash', $cursor, '*', 10);

        $this->assertEquals([0, ['field1' => 'value1', 'field2' => 'value2']], $result);
    }

    public function testSscan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('sscan')
            ->with('set', 0, '*', 10)
            ->once()
            ->andReturn(['member1', 'member2']);

        $result = $connection->sscan('set', $cursor, '*', 10);

        $this->assertEquals([0, ['member1', 'member2']], $result);
    }

    public function testEvalsha(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $redisConnection = $connection->getConnection();
        $redisConnection->shouldReceive('script')
            ->with('load', 'script')
            ->once()
            ->andReturn('sha1');

        $redisConnection->shouldReceive('evalSha')
            ->with('sha1', ['key1', 'key2'], 2)
            ->once()
            ->andReturn('result');

        $result = $connection->__call('evalsha', ['script', 2, 'key1', 'key2']);

        $this->assertEquals('result', $result);
    }

    public function testZaddWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('sortedset', ['NX', 'CH'], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zadd', ['sortedset', 'NX', 'CH', 1.0, 'member1', 2.0, 'member2']);

        $this->assertEquals(2, $result);
    }

    public function testZaddWithArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('sortedset', [], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zadd', ['sortedset', ['member1' => 1.0, 'member2' => 2.0]]);

        $this->assertEquals(2, $result);
    }

    public function testZrangebyscoreWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRangeByScore')
            ->with('sortedset', '1', '5', ['limit' => [0, 10]])
            ->once()
            ->andReturn(['member1', 'member2']);

        $result = $connection->__call('zrangebyscore', ['sortedset', '1', '5', ['limit' => ['offset' => 0, 'count' => 10]]]);

        $this->assertEquals(['member1', 'member2'], $result);
    }

    public function testFlushdbAsync(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('flushdb')
            ->with(true)
            ->once()
            ->andReturn(true);

        $result = $connection->__call('flushdb', ['ASYNC']);

        $this->assertTrue($result);
    }

    public function testFlushdbSync(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('flushdb')
            ->with()
            ->once()
            ->andReturn(true);

        $result = $connection->__call('flushdb', []);

        $this->assertTrue($result);
    }

    public function testExecuteRaw(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('rawCommand')
            ->with('CUSTOM', 'arg1', 'arg2')
            ->once()
            ->andReturn('result');

        $result = $connection->__call('executeRaw', [['CUSTOM', 'arg1', 'arg2']]);

        $this->assertEquals('result', $result);
    }

    public function testZinterstoreWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], [1, 2], 'max')
            ->once()
            ->andReturn(3);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2'], ['weights' => [1, 2], 'aggregate' => 'max']]);

        $this->assertEquals(3, $result);
    }

    public function testZunionstoreSimple(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->shouldTransform(false);

        $connection->getConnection()
            ->shouldReceive('zunionstore')
            ->withAnyArgs()
            ->once()
            ->andReturn(5);

        $result = $connection->__call('zunionstore', ['output', ['set1', 'set2']]);

        $this->assertEquals(5, $result);
    }

    public function testGetTransformsFalseToNull(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('get')
            ->with('key')
            ->once()
            ->andReturn(false);

        $result = $connection->__call('get', ['key']);

        $this->assertNull($result);
    }

    public function testSetWithoutOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('set')
            ->with('key', 'value', null)
            ->once()
            ->andReturn(true);

        $result = $connection->__call('set', ['key', 'value']);

        $this->assertTrue($result);
    }

    public function testSetnxReturnsZeroOnFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('setNx')
            ->with('key', 'value')
            ->once()
            ->andReturn(false);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertEquals(0, $result);
    }

    public function testHmsetWithAlternatingKeyValuePairs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->once()
            ->andReturn(true);

        // Laravel style: key, value, key, value
        $result = $connection->__call('hmset', ['hash', 'field1', 'value1', 'field2', 'value2']);

        $this->assertTrue($result);
    }

    public function testZaddWithScoreMemberPairs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('zset', [], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zadd', ['zset', 1.0, 'member1', 2.0, 'member2']);

        $this->assertEquals(2, $result);
    }

    public function testZrangebyscoreWithListLimitPassesThrough(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRangeByScore')
            ->with('zset', '-inf', '+inf', ['limit' => [5, 20]])
            ->once()
            ->andReturn(['member1']);

        // Already in list format - passes through
        $result = $connection->__call('zrangebyscore', ['zset', '-inf', '+inf', ['limit' => [5, 20]]]);

        $this->assertEquals(['member1'], $result);
    }

    public function testZrevrangebyscoreWithLimitOption(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRevRangeByScore')
            ->with('zset', '+inf', '-inf', ['limit' => [0, 5]])
            ->once()
            ->andReturn(['member2', 'member1']);

        $result = $connection->__call('zrevrangebyscore', ['zset', '+inf', '-inf', ['limit' => ['offset' => 0, 'count' => 5]]]);

        $this->assertEquals(['member2', 'member1'], $result);
    }

    public function testZinterstoreDefaultsAggregate(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2']]);

        $this->assertEquals(2, $result);
    }

    public function testCallWithoutTransformPassesDirectly(): void
    {
        $connection = $this->mockRedisConnection(transform: false);

        // Without transform, get() returns false (not null)
        $connection->getConnection()
            ->shouldReceive('get')
            ->with('key')
            ->once()
            ->andReturn(false);

        $result = $connection->__call('get', ['key']);

        $this->assertFalse($result);
    }

    public function testSerializedReturnsTrueWhenSerializerConfigured(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_PHP);

        $this->assertTrue($connection->serialized());
    }

    public function testSerializedReturnsFalseWhenNoSerializer(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_NONE);

        $this->assertFalse($connection->serialized());
    }

    public function testCompressedReturnsTrueWhenCompressionConfigured(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis::COMPRESSION_LZF is not defined.');
        }

        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_LZF);

        $this->assertTrue($connection->compressed());
    }

    public function testCompressedReturnsFalseWhenNoCompression(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        $this->assertFalse($connection->compressed());
    }

    public function testIsClusterReturnsFalseForStandardRedis(): void
    {
        $connection = $this->mockRedisConnection();

        // Default stub uses a Redis mock, so isCluster() should return false
        $this->assertFalse($connection->isCluster());
    }

    public function testIsClusterReturnsTrueForRedisCluster(): void
    {
        $connection = $this->mockRedisConnection();

        // Set a RedisCluster mock as the active connection
        $clusterMock = m::mock(RedisCluster::class)->shouldIgnoreMissing();
        $connection->setActiveConnection($clusterMock);

        $this->assertTrue($connection->isCluster());
    }

    public function testPackReturnsEmptyArrayForEmptyInput(): void
    {
        $connection = $this->mockRedisConnection();

        $result = $connection->pack([]);

        $this->assertSame([], $result);
    }

    public function testPackUsesNativePackMethod(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value1')
            ->once()
            ->andReturn('packed1');
        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value2')
            ->once()
            ->andReturn('packed2');

        $result = $connection->pack(['value1', 'value2']);

        $this->assertSame(['packed1', 'packed2'], $result);
    }

    public function testPackPreservesArrayKeys(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value1')
            ->once()
            ->andReturn('packed1');
        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value2')
            ->once()
            ->andReturn('packed2');

        $result = $connection->pack(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertSame([
            'key1' => 'packed1',
            'key2' => 'packed2',
        ], $result);
    }

    public function testEvalWithShaCacheSucceedsOnFirstTry(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return KEYS[1]';
        $sha = sha1($script);

        $connection->getConnection()
            ->shouldReceive('evalSha')
            ->with($sha, ['mykey', 'arg1', 'arg2'], 1)
            ->once()
            ->andReturn('mykey');

        $result = $connection->evalWithShaCache($script, ['mykey'], ['arg1', 'arg2']);

        $this->assertEquals('mykey', $result);
    }

    public function testEvalWithShaCacheThrowsOnNonNoscriptError(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'invalid lua syntax';
        $sha = sha1($script);

        $redisConnection = $connection->getConnection();

        $redisConnection->shouldReceive('evalSha')
            ->with($sha, ['mykey'], 1)
            ->once()
            ->andReturn(false);

        $redisConnection->shouldReceive('getLastError')
            ->once()
            ->andReturn('ERR Error compiling script');

        $this->expectException(LuaScriptException::class);
        $this->expectExceptionMessage('Lua script execution failed: ERR Error compiling script');

        $connection->evalWithShaCache($script, ['mykey']);
    }

    public function testEvalWithShaCacheReturnsLegitimatelyFalseResult(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return false';
        $sha = sha1($script);

        $redisConnection = $connection->getConnection();

        // Script returns false legitimately (no error)
        $redisConnection->shouldReceive('evalSha')
            ->with($sha, [], 0)
            ->once()
            ->andReturn(false);

        $redisConnection->shouldReceive('getLastError')
            ->once()
            ->andReturn(null); // No error - script legitimately returned false

        $result = $connection->evalWithShaCache($script);

        $this->assertFalse($result);
    }

    public function testEvalWithShaCacheWorksWithNoKeysOrArgs(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return 42';
        $sha = sha1($script);

        $connection->getConnection()
            ->shouldReceive('evalSha')
            ->with($sha, [], 0)
            ->once()
            ->andReturn(42);

        $result = $connection->evalWithShaCache($script);

        $this->assertEquals(42, $result);
    }

    public function testEvalWithShaCacheWorksWithMultipleKeysAndArgs(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return {KEYS[1], KEYS[2], ARGV[1], ARGV[2]}';
        $sha = sha1($script);

        $connection->getConnection()
            ->shouldReceive('evalSha')
            ->with($sha, ['key1', 'key2', 'arg1', 'arg2'], 2)
            ->once()
            ->andReturn(['key1', 'key2', 'arg1', 'arg2']);

        $result = $connection->evalWithShaCache($script, ['key1', 'key2'], ['arg1', 'arg2']);

        $this->assertEquals(['key1', 'key2', 'arg1', 'arg2'], $result);
    }

    public function testEvalWithShaCacheClearsLastErrorBeforeEvalSha(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return "ok"';
        $sha = sha1($script);

        $redisConnection = $connection->getConnection();

        // Verify clearLastError is called before evalSha using ordered expectations
        $redisConnection->shouldReceive('clearLastError')
            ->once()
            ->globally()
            ->ordered();

        $redisConnection->shouldReceive('evalSha')
            ->with($sha, [], 0)
            ->once()
            ->globally()
            ->ordered()
            ->andReturn('ok');

        $result = $connection->evalWithShaCache($script);

        $this->assertEquals('ok', $result);
    }

    public function testRetryAppliesGetTransform(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        // First get() throws RedisException, triggering retry
        $redis->shouldReceive('getMode')->andReturn(Redis::ATOMIC);
        $redis->shouldReceive('get')
            ->once()
            ->with('missing')
            ->andThrow(new RedisException('connection lost'));

        // After reconnect, get() returns false (key not found)
        $redis->shouldReceive('get')
            ->once()
            ->with('missing')
            ->andReturn(false);

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->shouldTransform(true);

        // With transform enabled, retry should return null (not false)
        $result = $connection->__call('get', ['missing']);

        $this->assertNull($result);
    }

    public function testRetryAppliesSetnxTransform(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->andReturn(Redis::ATOMIC);

        // First setNx() throws RedisException, triggering retry
        $redis->shouldReceive('setNx')
            ->once()
            ->with('key', 'value')
            ->andThrow(new RedisException('connection lost'));

        // After reconnect, setNx() returns true (phpredis bool)
        // Transform should cast to int (1)
        $redis->shouldReceive('setNx')
            ->once()
            ->with('key', 'value')
            ->andReturn(true);

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->shouldTransform(true);

        // Laravel setnx returns int (1), not bool (true)
        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame(1, $result);
    }

    public function testSpopWithoutCountReturnsSingleElement(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        // Without count, phpredis sPop returns a single string
        $connection->getConnection()
            ->shouldReceive('sPop')
            ->once()
            ->with('myset')
            ->andReturn('member1');

        $result = $connection->__call('spop', ['myset']);

        $this->assertSame('member1', $result);
    }

    public function testSpopWithCountReturnsArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        // With count, phpredis sPop returns an array
        $connection->getConnection()
            ->shouldReceive('sPop')
            ->once()
            ->with('myset', 3)
            ->andReturn(['member1', 'member2', 'member3']);

        $result = $connection->__call('spop', ['myset', 3]);

        $this->assertSame(['member1', 'member2', 'member3'], $result);
    }

    public function testSpopWithoutCountReturnsFalseForEmptySet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('sPop')
            ->once()
            ->with('emptyset')
            ->andReturn(false);

        $result = $connection->__call('spop', ['emptyset']);

        $this->assertFalse($result);
    }

    public function testEvalReordersArguments()
    {
        // Can't mock eval() on phpredis — Mockery's proxy falls through to the
        // C extension which tries a real connection. Instead, override callEval
        // to capture the arguments it receives after __call dispatches to it.
        $captured = [];
        $connection = new class($this->getContainer(), $this->getMockedPool(), [], $captured) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private array &$captured,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return m::mock(Redis::class)->shouldIgnoreMissing();
            }

            protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
            {
                $this->captured = [
                    'script' => $script,
                    'numberOfKeys' => $numberOfKeys,
                    'arguments' => $arguments,
                ];

                return 'captured';
            }
        };

        $connection->shouldTransform(true);

        // User calls: eval('return KEYS[1]', 1, 'mykey')
        $result = $connection->__call('eval', ['return KEYS[1]', 1, 'mykey']);

        $this->assertSame('captured', $result);
        $this->assertSame('return KEYS[1]', $captured['script']);
        $this->assertSame(1, $captured['numberOfKeys']);
        $this->assertSame(['mykey'], $captured['arguments']);
    }

    public function testEvalReordersMultipleArguments()
    {
        $captured = [];
        $connection = new class($this->getContainer(), $this->getMockedPool(), [], $captured) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private array &$captured,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return m::mock(Redis::class)->shouldIgnoreMissing();
            }

            protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
            {
                $this->captured = [
                    'script' => $script,
                    'numberOfKeys' => $numberOfKeys,
                    'arguments' => $arguments,
                ];

                return 'captured';
            }
        };

        $connection->shouldTransform(true);

        // User calls: eval('return {KEYS[1], ARGV[1]}', 1, 'mykey', 'myarg')
        $result = $connection->__call('eval', ['return {KEYS[1], ARGV[1]}', 1, 'mykey', 'myarg']);

        $this->assertSame('captured', $result);
        $this->assertSame('return {KEYS[1], ARGV[1]}', $captured['script']);
        $this->assertSame(1, $captured['numberOfKeys']);
        $this->assertSame(['mykey', 'myarg'], $captured['arguments']);
    }

    public function testEvalWithNoKeysOrArguments()
    {
        $captured = [];
        $connection = new class($this->getContainer(), $this->getMockedPool(), [], $captured) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private array &$captured,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return m::mock(Redis::class)->shouldIgnoreMissing();
            }

            protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
            {
                $this->captured = [
                    'script' => $script,
                    'numberOfKeys' => $numberOfKeys,
                    'arguments' => $arguments,
                ];

                return 'captured';
            }
        };

        $connection->shouldTransform(true);

        // User calls: eval('return 42', 0)
        $result = $connection->__call('eval', ['return 42', 0]);

        $this->assertSame('captured', $result);
        $this->assertSame('return 42', $captured['script']);
        $this->assertSame(0, $captured['numberOfKeys']);
        $this->assertSame([], $captured['arguments']);
    }

    public function testSubscribeWrapsCallbackArgumentOrder()
    {
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->once()
            ->andReturn(30.0);
        $redis->shouldReceive('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, -1)
            ->once();
        $redis->shouldReceive('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, 30.0)
            ->once();

        // Capture the wrapped callback that subscribe receives
        $capturedCallback = null;
        $redis->shouldReceive('subscribe')
            ->once()
            ->with(['channel1'], m::on(function ($callback) use (&$capturedCallback) {
                $capturedCallback = $callback;

                return true;
            }))
            ->andReturnTrue();

        $connection->setActiveConnection($redis);

        // User provides callback expecting ($message, $channel)
        $receivedArgs = [];
        $userCallback = function ($message, $channel) use (&$receivedArgs) {
            $receivedArgs = ['message' => $message, 'channel' => $channel];
        };

        $connection->__call('subscribe', [['channel1'], $userCallback]);

        // Simulate phpredis calling the wrapped callback with ($redis, $channel, $message)
        $this->assertNotNull($capturedCallback);
        $capturedCallback($redis, 'channel1', 'hello');

        $this->assertSame('hello', $receivedArgs['message']);
        $this->assertSame('channel1', $receivedArgs['channel']);
    }

    public function testPsubscribeWrapsCallbackArgumentOrder()
    {
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->once()
            ->andReturn(30.0);
        $redis->shouldReceive('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, -1)
            ->once();
        $redis->shouldReceive('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, 30.0)
            ->once();

        $capturedCallback = null;
        $redis->shouldReceive('psubscribe')
            ->once()
            ->with(['channel:*'], m::on(function ($callback) use (&$capturedCallback) {
                $capturedCallback = $callback;

                return true;
            }))
            ->andReturnTrue();

        $connection->setActiveConnection($redis);

        $receivedArgs = [];
        $userCallback = function ($message, $channel) use (&$receivedArgs) {
            $receivedArgs = ['message' => $message, 'channel' => $channel];
        };

        $connection->__call('psubscribe', [['channel:*'], $userCallback]);

        // Simulate phpredis calling the wrapped callback with ($redis, $pattern, $channel, $message)
        $this->assertNotNull($capturedCallback);
        $capturedCallback($redis, 'channel:*', 'channel:1', 'world');

        $this->assertSame('world', $receivedArgs['message']);
        $this->assertSame('channel:1', $receivedArgs['channel']);
    }

    public function testSubscribeRestoresReadTimeoutOnException()
    {
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_READ_TIMEOUT)
            ->once()
            ->andReturn(60.0);
        $redis->shouldReceive('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, -1)
            ->once();
        // Should restore even when subscribe throws
        $redis->shouldReceive('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, 60.0)
            ->once();

        $redis->shouldReceive('subscribe')
            ->once()
            ->andThrow(new RedisException('Subscribe failed'));

        $connection->setActiveConnection($redis);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('Subscribe failed');

        $connection->__call('subscribe', [['channel1'], function () {}]);
    }

    public function testSubscribeWrapsStringChannelAsArray()
    {
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getOption')->andReturn(0);
        $redis->shouldReceive('setOption')->twice();

        // Verify that a string channel gets wrapped in an array
        $redis->shouldReceive('subscribe')
            ->once()
            ->with(['single-channel'], m::type('Closure'))
            ->andReturnTrue();

        $connection->setActiveConnection($redis);

        $connection->__call('subscribe', ['single-channel', function () {}]);
    }

    public function testReconnectSetsSerializerOption()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'options' => ['serializer' => Redis::SERIALIZER_PHP]], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectSetsPrefixOption()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_PREFIX, 'myapp:');

        new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'options' => ['prefix' => 'myapp:']], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectThrowsOnUnknownOption()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        $this->expectException(\Hypervel\Redis\Exceptions\InvalidRedisOptionException::class);
        $this->expectExceptionMessage('The redis option key `bogus` is invalid.');

        new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'options' => ['bogus' => 'value']], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectSetsNumericOptions()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        // Numeric keys bypass the match statement and pass directly
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);

        new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'options' => [Redis::OPT_SERIALIZER => Redis::SERIALIZER_JSON]], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectAuthenticatesWhenAuthConfigured()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('auth')
            ->once()
            ->with('secret');

        new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'auth' => 'secret'], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectDoesNotAuthenticateWhenAuthEmpty()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldNotReceive('auth');

        new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379, 'auth' => ''], $redis) extends RedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testCloseNullsConnection()
    {
        $connection = $this->mockRedisConnection();

        // getActiveConnection() lazily creates the connection
        $connection->getActiveConnection();
        $this->assertNotNull($connection->client());

        $result = $connection->close();

        $this->assertTrue($result);
        $this->assertNull($connection->client());
    }

    public function testIsQueueingModeReturnsFalseForRedisCluster()
    {
        $connection = $this->mockRedisConnection(transform: true);

        // RedisCluster doesn't have getMode() in the isQueueingMode check —
        // the method returns false when connection is not Redis instance.
        // This means transforms still fire for cluster connections during pipeline.
        $clusterMock = m::mock(RedisCluster::class);
        $clusterMock->shouldReceive('setNx')
            ->once()
            ->with('key', 'value')
            ->andReturn(true);

        $connection->setActiveConnection($clusterMock);

        // With transform enabled on a cluster connection, callSetnx should fire
        // (isQueueingMode returns false for non-Redis)
        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame(1, $result);
    }

    public function testRetryFailureZeroesLastUseTime()
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('get')
            ->once()
            ->andThrow(new RedisException('first failure'));

        $connection = new class($this->getContainer(), $pool, ['host' => '127.0.0.1', 'port' => 6379], $redis) extends RedisConnection {
            private bool $constructed = false;

            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
                $this->constructed = true;
            }

            protected function createRedis(array $config): Redis
            {
                if ($this->constructed) {
                    // Retry's reconnect fails
                    throw new RedisException('reconnect failed');
                }

                // Initial construction succeeds
                return $this->fakeRedis;
            }

            public function getLastUseTime(): float
            {
                return $this->lastUseTime;
            }
        };

        // lastUseTime should be non-zero after initial construction
        $this->assertGreaterThan(0.0, $connection->getLastUseTime());

        try {
            $connection->__call('get', ['foo']);
            $this->fail('Expected RedisException');
        } catch (RedisException $exception) {
            $this->assertSame('reconnect failed', $exception->getMessage());
        }

        $this->assertSame(0.0, $connection->getLastUseTime());
    }

    public function testScanWithArrayOptions()
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, 'prefix:*', 20)
            ->once()
            ->andReturn(['key1', 'key2']);

        $result = $connection->scan($cursor, ['match' => 'prefix:*', 'count' => 20]);

        $this->assertEquals([0, ['key1', 'key2']], $result);
    }

    protected function mockRedisConnection(?ContainerContract $container = null, ?PoolInterface $pool = null, array $options = [], bool $transform = false): RedisConnection
    {
        $connection = new RedisConnectionStub(
            $container ?? $this->getContainer(),
            $pool ?? $this->getMockedPool(),
            $options
        );

        if ($transform) {
            $connection->shouldTransform(true);
        }

        return $connection;
    }

    protected function getMockedPool(): PoolInterface
    {
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')
            ->andReturn(m::mock(PoolOption::class));

        return $pool;
    }

    protected function getContainer(array $definitions = []): Container
    {
        $container = new Container();

        foreach ($definitions as $abstract => $concrete) {
            $container->singleton($abstract, $concrete);
        }

        return $container;
    }
}
