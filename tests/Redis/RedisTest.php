<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Exception;
use Hyperf\Pool\PoolOption;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;
use Redis as PhpRedis;
use RedisCluster;
use RedisSentinel;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Tests for the Redis class - the main public API.
 *
 * We mock RedisConnection entirely and verify the Redis class properly
 * manages connections, context storage, and command proxying.
 *
 * @internal
 * @coversNothing
 */
class RedisTest extends TestCase
{
    use RunTestsInCoroutine;

    protected bool $isOlderThan6 = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isOlderThan6 = version_compare((string) phpversion('redis'), '6.0.0', '<');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Context::destroy('redis.connection.default');
    }

    public function testCommandIsProxiedToConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('foo')->andReturn('bar');
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->get('foo');

        $this->assertSame('bar', $result);
    }

    public function testConnectionIsStoredInContextForMulti(): void
    {
        $multiInstance = m::mock(PhpRedis::class);

        $connection = $this->mockConnection();
        $connection->shouldReceive('multi')->once()->andReturn($multiInstance);
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->multi();

        $this->assertSame($multiInstance, $result);
        // Connection should be stored in context
        $this->assertTrue(Context::has('redis.connection.default'));
    }

    public function testConnectionIsStoredInContextForPipeline(): void
    {
        $pipelineInstance = m::mock(PhpRedis::class);

        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn($pipelineInstance);
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->pipeline();

        $this->assertSame($pipelineInstance, $result);
        $this->assertTrue(Context::has('redis.connection.default'));
    }

    public function testConnectionIsStoredInContextForSelect(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('select')->once()->with(1)->andReturn(true);
        $connection->shouldReceive('setDatabase')->once()->with(1);
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->select(1);

        $this->assertTrue($result);
        $this->assertTrue(Context::has('redis.connection.default'));
    }

    public function testExistingContextConnectionIsReused(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->twice()->andReturn('value1', 'value2');
        // Connection is NOT released during the test (it already existed in context),
        // but allow release() call for test cleanup
        $connection->shouldReceive('release')->zeroOrMoreTimes();

        // Pre-set connection in context
        Context::set('redis.connection.default', $connection);

        $redis = $this->createRedis($connection);

        // Both calls should use the same connection from context
        $result1 = $redis->get('key1');
        $result2 = $redis->get('key2');

        $this->assertSame('value1', $result1);
        $this->assertSame('value2', $result2);
    }

    public function testExceptionIsPropagated(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('Redis error'));
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis error');

        $redis->get('key');
    }

    public function testExceptionWithContextConnectionDoesNotReleaseConnection(): void
    {
        $expectedException = new Exception('Redis error');

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException);
        $mockRedisConnection->shouldReceive('release')->never();

        // Pre-set context connection
        Context::set('redis.connection.default', $mockRedisConnection);

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Redis error', $e->getMessage());
        }
    }

    public function testExceptionWithSameConnectionCommandReleasesConnectionInsteadOfStoring(): void
    {
        $expectedException = new Exception('Multi failed');

        $mockRedisConnection = $this->createMockRedisConnection('multi', null, $expectedException);
        // On error, connection should be released, NOT stored in context
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->multi();
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Multi failed', $e->getMessage());
        }

        // Connection should NOT be stored in context on error
        $this->assertNull(Context::get('redis.connection.default'));
    }

    public function testEventDispatchedOnSuccess(): void
    {
        $mockEventDispatcher = m::mock(EventDispatcherInterface::class);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (CommandExecuted $event) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->result === 'value'
                    && $event->throwable === null;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', 'value', null, $mockEventDispatcher);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');
    }

    public function testEventDispatchedOnErrorWithExceptionInfo(): void
    {
        $expectedException = new Exception('Redis error');

        $mockEventDispatcher = m::mock(EventDispatcherInterface::class);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (CommandExecuted $event) use ($expectedException) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->result === null
                    && $event->throwable === $expectedException;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException, $mockEventDispatcher);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    public function testRegularCommandDoesNotStoreConnectionInContext(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection();
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');

        $this->assertNull(Context::get('redis.connection.default'));
    }

    public function testWithConnectionExecutesCallbackAndReleasesConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->withConnection(function (RedisConnection $conn) use ($connection) {
            $this->assertSame($connection, $conn);

            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testWithConnectionReusesExistingContextConnection(): void
    {
        $connection = $this->mockConnection();
        // Should NOT release since connection was already in context
        $connection->shouldReceive('release')->never();

        // Pre-set connection in context (simulating an active multi/pipeline)
        Context::set('redis.connection.default', $connection);

        $redis = $this->createRedis($connection);

        $result = $redis->withConnection(function (RedisConnection $conn) use ($connection) {
            $this->assertSame($connection, $conn);

            return 'reused-connection';
        });

        $this->assertSame('reused-connection', $result);
        // Connection should still be in context
        $this->assertTrue(Context::has('redis.connection.default'));
    }

    public function testWithConnectionReleasesOnException(): void
    {
        $connection = $this->mockConnection();
        // Should release even on exception
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis->withConnection(function (RedisConnection $conn) {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testWithConnectionDoesNotReleaseContextConnectionOnException(): void
    {
        $connection = $this->mockConnection();
        // Should NOT release since connection was in context
        $connection->shouldReceive('release')->never();

        Context::set('redis.connection.default', $connection);

        $redis = $this->createRedis($connection);

        try {
            $redis->withConnection(function (RedisConnection $conn) {
                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Callback failed', $e->getMessage());
        }

        // Connection should still be in context
        $this->assertTrue(Context::has('redis.connection.default'));
    }

    public function testWithConnectionDefaultsToTransformTrue(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')
            ->once()
            ->with(true)
            ->andReturnSelf();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $conn) {
            return 'result';
        });
    }

    public function testWithConnectionRespectsTransformFalse(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')
            ->once()
            ->with(false)
            ->andReturnSelf();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $conn) {
            return 'result';
        }, transform: false);
    }

    public function testWithConnectionRespectsTransformTrueExplicit(): void
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')
            ->once()
            ->with(true)
            ->andReturnSelf();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $conn) {
            return 'result';
        }, transform: true);
    }

    public function testWithConnectionAllowsMultipleOperationsOnSameConnection(): void
    {
        $mockPhpRedis = m::mock(PhpRedis::class);
        $mockPhpRedis->shouldReceive('evalSha')
            ->once()
            ->with('sha123', ['key'], 1)
            ->andReturn(false);
        $mockPhpRedis->shouldReceive('getLastError')
            ->once()
            ->andReturn('NOSCRIPT No matching script');

        $connection = $this->mockConnection();
        $connection->shouldReceive('client')->andReturn($mockPhpRedis);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $evalResult = $client->evalSha('sha123', ['key'], 1);

            if ($evalResult === false) {
                return $client->getLastError();
            }

            return $evalResult;
        });

        $this->assertSame('NOSCRIPT No matching script', $result);
    }

    public function testRedisClusterConstructorSignature(): void
    {
        $reflection = new ReflectionClass(RedisCluster::class);
        $method = $reflection->getMethod('__construct');
        $names = [
            ['name', 'string'],
            ['seeds', 'array'],
            ['timeout', ['int', 'float']],
            ['read_timeout', ['int', 'float']],
            ['persistent', 'bool'],
            ['auth', 'mixed'],
            ['context', 'array'],
        ];

        foreach ($method->getParameters() as $parameter) {
            [$name, $type] = array_shift($names);
            $this->assertSame($name, $parameter->getName());

            if ($parameter->getName() === 'seeds') {
                $this->assertSame('array', $parameter->getType()?->getName());
                continue;
            }

            if ($this->isOlderThan6) {
                $this->assertNull($parameter->getType());
                continue;
            }

            if (is_array($type)) {
                foreach ($parameter->getType()?->getTypes() ?? [] as $namedType) {
                    $this->assertTrue(in_array($namedType->getName(), $type, true));
                }

                continue;
            }

            $this->assertSame($type, $parameter->getType()?->getName());
        }
    }

    public function testRedisSentinelConstructorSignature(): void
    {
        $reflection = new ReflectionClass(RedisSentinel::class);
        $method = $reflection->getMethod('__construct');
        $count = count($method->getParameters());

        if (! $this->isOlderThan6) {
            $this->assertSame(1, $count);
            $this->assertSame('options', $method->getParameters()[0]->getName());

            return;
        }

        if ($count === 6) {
            $this->markTestIncomplete('RedisSentinel does not support auth in this extension variant.');
        }

        $this->assertSame(7, $count);
    }

    public function testShuffleNodesMaintainsNodeCount(): void
    {
        $nodes = ['127.0.0.1:6379', '127.0.0.1:6378', '127.0.0.1:6377'];

        shuffle($nodes);

        $this->assertIsArray($nodes);
        $this->assertSame(3, count($nodes));
    }

    /**
     * Create a mock RedisConnection with standard expectations.
     */
    private function mockConnection(): m\MockInterface|RedisConnection
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')->andReturnSelf();

        return $connection;
    }

    /**
     * Create a Redis instance with the given mock connection.
     */
    private function createRedis(m\MockInterface|RedisConnection $connection): Redis
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);
        $pool->shouldReceive('getOption')->andReturn(m::mock(PoolOption::class));

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        return new Redis($poolFactory);
    }

    /**
     * Create a mock Redis connection with configurable behavior.
     */
    private function createMockRedisConnection(
        string $command = 'get',
        mixed $returnValue = 'value',
        ?Throwable $exception = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ): RedisConnection&m\MockInterface {
        $mockPhpRedis = m::mock(PhpRedis::class);

        if ($exception !== null) {
            $mockPhpRedis->shouldReceive($command)
                ->andThrow($exception);
        } else {
            $mockPhpRedis->shouldReceive($command)
                ->andReturn($returnValue);
        }

        $mockRedisConnection = m::mock(RedisConnection::class);
        $mockRedisConnection->shouldReceive('shouldTransform')->andReturnSelf();
        $mockRedisConnection->shouldReceive('getConnection')->andReturn($mockRedisConnection);
        $mockRedisConnection->shouldReceive('getEventDispatcher')->andReturn($eventDispatcher);

        // Forward the command call to the mock PHP Redis
        $mockRedisConnection->shouldReceive($command)
            ->andReturnUsing(function (...$args) use ($mockPhpRedis, $command) {
                return $mockPhpRedis->{$command}(...$args);
            });

        return $mockRedisConnection;
    }
}
