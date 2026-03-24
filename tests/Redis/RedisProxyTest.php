<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Context\Context;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\Redis as HypervelRedis;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis;

/**
 * Tests for RedisProxy.
 *
 * RedisProxy extends Redis and sets a custom pool name.
 * This tests that the pool name is properly used.
 *
 * @internal
 * @coversNothing
 */
class RedisProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Context::forget(HypervelRedis::CONNECTION_CONTEXT_PREFIX . 'default');
        Context::forget(HypervelRedis::CONNECTION_CONTEXT_PREFIX . 'cache');
    }

    public function testProxyUsesSpecifiedPoolName(): void
    {
        $cacheConnection = $this->mockConnection();
        $cacheConnection->shouldReceive('get')->once()->with('key')->andReturn('cached');
        $cacheConnection->shouldReceive('release')->once();

        $cachePool = m::mock(RedisPool::class);
        $cachePool->shouldReceive('get')->andReturn($cacheConnection);

        $poolFactory = m::mock(PoolFactory::class);
        // Expect 'cache' pool to be requested, not 'default'
        $poolFactory->shouldReceive('getPool')->with('cache')->andReturn($cachePool);

        $proxy = new RedisProxy($poolFactory, 'cache');

        $result = $proxy->get('key');

        $this->assertSame('cached', $result);
    }

    public function testProxyContextKeyUsesPoolName(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn(m::mock(Redis::class));
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('cache')->andReturn($pool);

        $proxy = new RedisProxy($poolFactory, 'cache');

        $proxy->pipeline();

        // Context key should use the pool name
        $this->assertTrue(Context::has(HypervelRedis::CONNECTION_CONTEXT_PREFIX . 'cache'));
        $this->assertFalse(Context::has(HypervelRedis::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testIsClusterReadsCorrectPoolConfig(): void
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('getConfig')->andReturn([
            'cluster' => ['enable' => true, 'seeds' => ['127.0.0.1:6379']],
        ]);
        $pool->shouldReceive('get')->never();

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('cache')->andReturn($pool);

        $proxy = new RedisProxy($poolFactory, 'cache');

        $this->assertTrue($proxy->isCluster());
    }

    /**
     * Create a mock PhpRedisConnection with standard expectations.
     */
    private function mockConnection(): m\MockInterface|PhpRedisConnection
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')->andReturnSelf();

        return $connection;
    }
}
