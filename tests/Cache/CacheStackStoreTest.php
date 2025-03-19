<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Carbon\Carbon;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\SwooleStore;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class CacheStackStoreTest extends TestCase
{
    /** @var MockInterface|SwooleStore */
    private SwooleStore $swoole;

    /** @var MockInterface|RedisStore */
    private RedisStore $redis;

    private StackStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2000-01-01 12:34:56.123456');
    }

    public function testRetrieveItemFromStoreStacked()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testPutWithCorrectTTL()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        Carbon::setTestNow(Carbon::now()->addSeconds(50));

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl - 50)->andReturn(true);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testAvoidRedundantCall()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn($record);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testMissingItemsReturnNull()
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn(null);

        $this->assertNull($this->store->get($key));
    }

    public function testPutItemToStoreStacked()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertTrue($this->store->put($key, $value, $ttl));
    }

    public function testPutItemToStoreFailed()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);

        $this->assertFalse($this->store->put($key, $value, $ttl));
    }

    public function testPutItemToStoreFailedAndRollback()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);
        $this->swoole->shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->assertFalse($this->store->put($key, $value, $ttl));
    }

    public function testMany()
    {
        $this->createStores();

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturn(['value' => 'bar']);
        $this->swoole->shouldReceive('get')->once()->with('bar')->andReturn(['value' => 'baz']);

        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $this->store->many(['foo', 'bar']));
    }

    public function testPutMany()
    {
        $this->createStores();

        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('put')->once()->with('foo', ['value' => 'bar', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with('foo', ['value' => 'bar', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->swoole->shouldReceive('put')->once()->with('bar', ['value' => 'baz', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with('bar', ['value' => 'baz', 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->store->putMany(['foo' => 'bar', 'bar' => 'baz'], $ttl);
    }

    public function testIncrement()
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => 1])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => 1])->andReturn(true);
        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 1]);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => 3])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => 3])->andReturn(true);

        $this->assertSame(1, $this->store->increment($key));
        $this->assertSame(3, $this->store->increment($key, 2));
    }

    public function testIncrementWithTTL()
    {
        $this->createStores();

        $key = 'foo';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 1, 'expiration' => $expiration]);
        $this->swoole->shouldReceive('put')->once()->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertSame(2, $this->store->increment($key));
    }

    public function testDecrement()
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => -1])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => -1])->andReturn(true);
        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => -1]);
        $this->swoole->shouldReceive('forever')->once()->with($key, ['value' => -3])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with($key, ['value' => -3])->andReturn(true);

        $this->assertSame(-1, $this->store->decrement($key));
        $this->assertSame(-3, $this->store->decrement($key, 2));
    }

    public function testDecrementWithTTL()
    {
        $this->createStores();

        $key = 'foo';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 2, 'expiration' => $expiration]);
        $this->swoole->shouldReceive('put')->once()->with($key, ['value' => 1, 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, ['value' => 1, 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertSame(1, $this->store->decrement($key));
    }

    public function testForever()
    {
        $this->createStores();

        $this->swoole->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(true);

        $this->assertTrue($this->store->forever('foo', 'bar'));
    }

    public function testForeverFailed()
    {
        $this->createStores();

        $this->swoole->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(false);

        $this->assertFalse($this->store->forever('foo', 'bar'));
    }

    public function testForeverFailedWithRollback()
    {
        $this->createStores();

        $this->swoole->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(true);
        $this->redis->shouldReceive('forever')->once()->with('foo', ['value' => 'bar'])->andReturn(false);
        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturn(true);

        $this->assertFalse($this->store->forever('foo', 'bar'));
    }

    public function testForget()
    {
        $this->createStores();

        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturn(true);
        $this->redis->shouldReceive('forget')->once()->with('foo')->andReturn(true);

        $this->assertTrue($this->store->forget('foo', 'bar'));
    }

    public function testForgetFailed()
    {
        $this->createStores();

        $this->swoole->shouldReceive('forget')->once()->with('foo')->andReturn(false);
        $this->redis->shouldReceive('forget')->once()->with('foo')->andReturn(true);

        $this->assertTrue($this->store->forget('foo', 'bar'));
    }

    public function testFlush()
    {
        $this->createStores();

        $this->swoole->shouldReceive('flush')->once()->withNoArgs()->andReturn(true);
        $this->redis->shouldReceive('flush')->once()->withNoArgs()->andReturn(true);

        $this->assertTrue($this->store->flush('foo', 'bar'));
    }

    public function testFlushFailed()
    {
        $this->createStores();

        $this->swoole->shouldReceive('flush')->once()->withNoArgs()->andReturn(false);
        $this->redis->shouldReceive('flush')->once()->withNoArgs()->andReturn(true);

        $this->assertTrue($this->store->flush('foo', 'bar'));
    }

    public function testThreeStores()
    {
        /** @var ArrayStore|MockInterface $array */
        $array = m::mock(ArrayStore::class);
        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $store = new StackStore([$array, $swoole, $redis]);

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $array->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->assertSame($value, $store->get($key));

        $array->shouldReceive('get')->once()->with($key)->andReturn(null);
        $swoole->shouldReceive('get')->once()->with($key)->andReturn($record);
        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->assertSame($value, $store->get($key));

        $array->shouldReceive('get')->once()->with($key)->andReturn(null);
        $swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->assertSame($value, $store->get($key));

        $array->shouldReceive('get')->once()->with($key)->andReturn(null);
        $swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $redis->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->assertNull($store->get($key));

        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->assertTrue($store->put($key, $value, $ttl));

        $array->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);
        $swoole->shouldReceive('forget')->once()->with($key)->andReturn(true);
        $array->shouldReceive('forget')->once()->with($key)->andReturn(true);
        $this->assertFalse($store->put($key, $value, $ttl));
    }

    public function testInvalidRecord()
    {
        $this->createStores();

        $key = 'foo';

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn('invalid record');
        $this->assertNull($this->store->get($key));

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn('invalid record');
        $this->assertNull($this->store->get($key));
    }

    public function testProxyMaxTTL()
    {
        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $maxTTL = 3;
        $expiration = Carbon::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->shouldReceive('put')->once()->with($key, $record, $maxTTL)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertTrue($store->put($key, $value, $ttl));
    }

    public function testProxyMaxTTLWithForever()
    {
        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $maxTTL = 3;
        $record = compact('value');

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->shouldReceive('put')->once()->with($key, $record, $maxTTL)->andReturn(true);
        $redis->shouldReceive('forever')->once()->with($key, $record)->andReturn(true);

        $this->assertTrue($store->forever($key, $value));
    }

    private function createStores()
    {
        $this->redis = m::mock(RedisStore::class);
        $this->swoole = m::mock(SwooleStore::class);
        $this->store = new StackStore([$this->swoole, $this->redis]);
    }
}
