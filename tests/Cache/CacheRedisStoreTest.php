<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Cache\Redis\RedisCacheTestCase;
use Mockery as m;

class CacheRedisStoreTest extends RedisCacheTestCase
{
    public function testGetReturnsNullWhenNotFound()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(null);

        $store = $this->createStore($connection);
        $this->assertNull($store->get('foo'));
    }

    public function testRedisValueIsReturned()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize('foo'));

        $store = $this->createStore($connection);
        $this->assertSame('foo', $store->get('foo'));
    }

    public function testRedisMultipleValuesAreReturned()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('mget')->once()->with(['prefix:foo', 'prefix:fizz', 'prefix:norf', 'prefix:null'])
            ->andReturn([
                serialize('bar'),
                serialize('buzz'),
                serialize('quz'),
                null,
            ]);

        $store = $this->createStore($connection);
        $results = $store->many(['foo', 'fizz', 'norf', 'null']);

        $this->assertSame('bar', $results['foo']);
        $this->assertSame('buzz', $results['fizz']);
        $this->assertSame('quz', $results['norf']);
        $this->assertNull($results['null']);
    }

    public function testRedisValueIsReturnedForNumerics()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('prefix:foo')->andReturn(1);

        $store = $this->createStore($connection);
        $this->assertEquals(1, $store->get('foo'));
    }

    public function testSetMethodProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('setex')->once()->with('prefix:foo', 60, serialize('foo'))->andReturn('OK');

        $store = $this->createStore($connection);
        $result = $store->put('foo', 'foo', 60);
        $this->assertTrue($result);
    }

    public function testSetMultipleMethodProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        // Hypervel uses a Lua script for putMany in standard mode (more performant than multi/exec).
        // The Lua script receives all keys and serialized values in a single EVALSHA call.
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(true);

        $store = $this->createStore($connection);
        $result = $store->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
            'bar' => 'norf',
        ], 60);
        $this->assertTrue($result);
    }

    public function testSetMethodProperlyCallsRedisForNumerics()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('setex')->once()->with('prefix:foo', 60, 1);

        $store = $this->createStore($connection);
        $result = $store->put('foo', 1, 60);
        $this->assertFalse($result);
    }

    public function testIncrementMethodProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('incrBy')->once()->with('prefix:foo', 5)->andReturn(5);

        $store = $this->createStore($connection);
        $store->increment('foo', 5);
    }

    public function testDecrementMethodProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('decrBy')->once()->with('prefix:foo', 5)->andReturn(-5);

        $store = $this->createStore($connection);
        $store->decrement('foo', 5);
    }

    public function testStoreItemForeverProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('set')->once()->with('prefix:foo', serialize('foo'))->andReturn('OK');

        $store = $this->createStore($connection);
        $result = $store->forever('foo', 'foo');
        $this->assertTrue($result);
    }

    public function testTouchMethodProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('expire')->once()->with('prefix:key', 60)->andReturn(true);

        $store = $this->createStore($connection);
        $this->assertTrue($store->touch('key', 60));
    }

    public function testForgetMethodProperlyCallsRedis()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('del')->once()->with('prefix:foo');

        $store = $this->createStore($connection);
        $store->forget('foo');
    }

    public function testFlushesCached()
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('flushdb')->once()->andReturn('ok');

        $store = $this->createStore($connection);
        $result = $store->flush();
        $this->assertTrue($result);
    }

    public function testFlushesCachedLocks()
    {
        $lockProxy = m::mock(RedisProxy::class);
        $lockProxy->shouldReceive('flushdb')->once()->andReturn('ok');

        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->with('locks')->once()->andReturn($lockProxy);

        $store = new RedisStore(
            $redis,
            'prefix:',
            'default',
            $this->createPoolFactory($this->mockConnection())
        );
        $store->setLockConnection('locks');

        $result = $store->flushLocks();
        $this->assertTrue($result);
    }

    public function testGetAndSetPrefix()
    {
        $store = $this->createStore($this->mockConnection());
        $this->assertSame('prefix:', $store->getPrefix());
        $store->setPrefix('foo');
        $this->assertSame('foo', $store->getPrefix());
        $store->setPrefix(null);
        $this->assertEmpty($store->getPrefix());
    }
}
