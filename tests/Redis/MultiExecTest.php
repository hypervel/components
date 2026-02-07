<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis as PhpRedis;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class MultiExecTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        parent::tearDown();
        Context::destroy('redis.connection.default');
    }

    public function testPipelineWithoutCallbackReturnsInstanceForChaining(): void
    {
        $pipelineInstance = m::mock(PhpRedis::class);

        $phpRedis = m::mock(PhpRedis::class);
        $phpRedis->shouldReceive('pipeline')->once()->andReturn($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        // Connection is stored in context and released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();
        $redis = $this->createRedis($connection);

        $result = $redis->pipeline();

        // Without callback, returns the pipeline instance for chaining
        $this->assertSame($pipelineInstance, $result);
    }

    public function testPipelineWithCallbackExecutesAndReturnsResults(): void
    {
        $execResults = ['OK', 'OK', 'value'];

        $pipelineInstance = m::mock(PhpRedis::class);
        $pipelineInstance->shouldReceive('set')->twice()->andReturnSelf();
        $pipelineInstance->shouldReceive('get')->once()->andReturnSelf();
        $pipelineInstance->shouldReceive('exec')->once()->andReturn($execResults);

        $phpRedis = m::mock(PhpRedis::class);
        $phpRedis->shouldReceive('pipeline')->once()->andReturn($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->shouldReceive('release')->once();
        $redis = $this->createRedis($connection);

        $result = $redis->pipeline(function ($pipe) {
            $pipe->set('key1', 'value1');
            $pipe->set('key2', 'value2');
            $pipe->get('key1');
        });

        $this->assertSame($execResults, $result);
    }

    public function testTransactionWithoutCallbackReturnsInstanceForChaining(): void
    {
        $multiInstance = m::mock(PhpRedis::class);

        $phpRedis = m::mock(PhpRedis::class);
        $phpRedis->shouldReceive('multi')->once()->andReturn($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        // Connection is stored in context and released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();
        $redis = $this->createRedis($connection);

        $result = $redis->transaction();

        // Without callback, returns the multi instance for chaining
        $this->assertSame($multiInstance, $result);
    }

    public function testTransactionWithCallbackExecutesAndReturnsResults(): void
    {
        $execResults = ['OK', 5];

        $multiInstance = m::mock(PhpRedis::class);
        $multiInstance->shouldReceive('set')->once()->andReturnSelf();
        $multiInstance->shouldReceive('incr')->once()->andReturnSelf();
        $multiInstance->shouldReceive('exec')->once()->andReturn($execResults);

        $phpRedis = m::mock(PhpRedis::class);
        $phpRedis->shouldReceive('multi')->once()->andReturn($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->shouldReceive('release')->once();
        $redis = $this->createRedis($connection);

        $result = $redis->transaction(function ($tx) {
            $tx->set('key', 'value');
            $tx->incr('counter');
        });

        $this->assertSame($execResults, $result);
    }

    public function testPipelineWithCallbackDoesNotReleaseExistingContextConnection(): void
    {
        $pipelineInstance = m::mock(PhpRedis::class);
        $pipelineInstance->shouldReceive('exec')->once()->andReturn([]);

        $phpRedis = m::mock(PhpRedis::class);
        $phpRedis->shouldReceive('pipeline')->once()->andReturn($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        // Set up existing connection in context BEFORE the pipeline call
        Context::set('redis.connection.default', $connection);

        // Connection is NOT released during the test (it already existed in context),
        // but allow release() call for test cleanup
        $connection->shouldReceive('release')->zeroOrMoreTimes();

        $redis = $this->createRedis($connection);

        $redis->pipeline(function ($pipe) {
            // empty callback
        });
    }

    public function testPipelineWithCallbackReleasesOnException(): void
    {
        $pipelineInstance = m::mock(PhpRedis::class);
        // exec throws exception
        $pipelineInstance->shouldReceive('exec')->once()->andThrow(new RuntimeException('Redis error'));

        $phpRedis = m::mock(PhpRedis::class);
        $phpRedis->shouldReceive('pipeline')->once()->andReturn($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        // Connection should still be released even on exception
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis error');

        $redis->pipeline(function ($pipe) {
            // callback runs, but exec will throw
        });
    }

    /**
     * Create a mock RedisConnection.
     */
    private function createMockConnection(m\MockInterface $phpRedis): m\MockInterface|RedisConnection
    {
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('setDatabase')->andReturnNull();
        $connection->shouldReceive('shouldTransform')->andReturnSelf();

        // Forward method calls to the phpRedis mock
        $connection->shouldReceive('pipeline')->andReturnUsing(fn () => $phpRedis->pipeline());
        $connection->shouldReceive('multi')->andReturnUsing(fn () => $phpRedis->multi());

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
