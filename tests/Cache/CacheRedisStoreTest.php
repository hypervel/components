<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hyperf\Redis\RedisFactory as Factory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Cache\RedisStore;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CacheRedisStoreTest extends TestCase
{
    public function testGetReturnsNullWhenNotFound()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('get')->once()->with('prefix:foo')->andReturn(null);
        $this->assertNull($redis->get('foo'));
    }

    public function testRedisValueIsReturned()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('get')->once()->with('prefix:foo')->andReturn(serialize('foo'));
        $this->assertSame('foo', $redis->get('foo'));
    }

    public function testRedisMultipleValuesAreReturned()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('mget')->once()->with(['prefix:foo', 'prefix:fizz', 'prefix:norf', 'prefix:null'])
            ->andReturn([
                serialize('bar'),
                serialize('buzz'),
                serialize('quz'),
                null,
            ]);

        $results = $redis->many(['foo', 'fizz', 'norf', 'null']);

        $this->assertSame('bar', $results['foo']);
        $this->assertSame('buzz', $results['fizz']);
        $this->assertSame('quz', $results['norf']);
        $this->assertNull($results['null']);
    }

    public function testRedisValueIsReturnedForNumerics()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('get')->once()->with('prefix:foo')->andReturn(1);
        $this->assertEquals(1, $redis->get('foo'));
    }

    public function testSetMethodProperlyCallsRedis()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('setex')->once()->with('prefix:foo', 60, serialize('foo'))->andReturn('OK');
        $result = $redis->put('foo', 'foo', 60);
        $this->assertTrue($result);
    }

    public function testSetMultipleMethodProperlyCallsRedis()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('multi')->once();
        $proxy->shouldReceive('setex')->once()->with('prefix:foo', 60, serialize('bar'))->andReturn('OK');
        $proxy->shouldReceive('setex')->once()->with('prefix:baz', 60, serialize('qux'))->andReturn('OK');
        $proxy->shouldReceive('setex')->once()->with('prefix:bar', 60, serialize('norf'))->andReturn('OK');
        $proxy->shouldReceive('exec')->once();

        $result = $redis->putMany([
            'foo' => 'bar',
            'baz' => 'qux',
            'bar' => 'norf',
        ], 60);
        $this->assertTrue($result);
    }

    public function testSetMethodProperlyCallsRedisForNumerics()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('setex')->once()->with('prefix:foo', 60, 1);
        $result = $redis->put('foo', 1, 60);
        $this->assertFalse($result);
    }

    public function testIncrementMethodProperlyCallsRedis()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('incrby')->once()->with('prefix:foo', 5)->andReturn(6);
        $result = $redis->increment('foo', 5);
        $this->assertEquals(6, $result);
    }

    public function testDecrementMethodProperlyCallsRedis()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('decrby')->once()->with('prefix:foo', 5)->andReturn(4);
        $result = $redis->decrement('foo', 5);
        $this->assertEquals(4, $result);
    }

    public function testStoreItemForeverProperlyCallsRedis()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('set')->once()->with('prefix:foo', serialize('foo'))->andReturn('OK');
        $result = $redis->forever('foo', 'foo', 60);
        $this->assertTrue($result);
    }

    public function testForgetMethodProperlyCallsRedis()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('del')->once()->with('prefix:foo');
        $redis->forget('foo');
        $this->assertTrue(true);
    }

    public function testFlushesCached()
    {
        $redis = $this->getRedis();
        $redis->getRedis()->shouldReceive('get')->once()->with('default')->andReturn($proxy = $this->getRedisProxy());
        $proxy->shouldReceive('flushdb')->once()->andReturn('ok');
        $result = $redis->flush();
        $this->assertTrue($result);
    }

    public function testGetAndSetPrefix()
    {
        $redis = $this->getRedis();
        $this->assertSame('prefix:', $redis->getPrefix());
        $redis->setPrefix('foo');
        $this->assertSame('foo:', $redis->getPrefix());
        $redis->setPrefix('');
        $this->assertEmpty($redis->getPrefix());
    }

    protected function getRedis()
    {
        return new RedisStore(m::mock(Factory::class), 'prefix');
    }

    protected function getRedisProxy()
    {
        return m::mock(RedisProxy::class);
    }
}
