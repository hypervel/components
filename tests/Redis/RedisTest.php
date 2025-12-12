<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis as PhpRedis;
use RuntimeException;

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

    public function testNullReturnedOnExceptionWhenContextConnectionExists(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('Error'));
        // Connection is NOT released during the test (it already existed in context),
        // but allow release() call for test cleanup
        $connection->shouldReceive('release')->zeroOrMoreTimes();

        // Pre-set connection in context
        Context::set('redis.connection.default', $connection);

        $redis = $this->createRedis($connection);

        // When context connection exists and error occurs, null is returned
        // (the return in finally supersedes the throw in catch)
        $result = $redis->get('key');

        $this->assertNull($result);
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

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        return new Redis($poolFactory);
    }
}
