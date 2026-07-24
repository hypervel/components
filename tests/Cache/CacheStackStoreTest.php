<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\SwooleStore;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use RuntimeException;

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

        CarbonImmutable::setTestNow('2000-01-01 12:34:56.123456');
    }

    public function testRetrieveItemFromStoreStacked()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);

        $this->assertSame($value, $this->store->get($key));
    }

    public function testReadReturnsLowerValueWhenUpperRepairFails(): void
    {
        $this->createStores();

        $record = ['value' => 'bar'];

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturn($record);
        $this->swoole->shouldReceive('forever')->once()->with('foo', $record)->andReturnFalse();

        $this->assertSame('bar', $this->store->get('foo'));
    }

    public function testMalformedLowerRecordRemainsAMiss(): void
    {
        $this->createStores();

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturn(['expiration' => 123]);

        $this->assertNull($this->store->get('foo'));
    }

    public function testReadRepairExceptionPropagates(): void
    {
        $this->createStores();

        $exception = new RuntimeException('repair failed');
        $record = ['value' => 'bar'];

        $this->swoole->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $this->redis->shouldReceive('get')->once()->with('foo')->andReturn($record);
        $this->swoole->shouldReceive('forever')->once()->with('foo', $record)->andThrow($exception);

        try {
            $this->store->get('foo');
            $this->fail('Expected the read repair exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testThreeLayerReadContinuesRepairAfterInnerFailure(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $record = ['value' => 'bar'];

        $top->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $middle->shouldReceive('get')->once()->with('foo')->andReturnNull();
        $bottom->shouldReceive('get')->once()->with('foo')->andReturn($record);
        $middle->shouldReceive('forever')->once()->with('foo', $record)->andReturnFalse();
        $top->shouldReceive('forever')->once()->with('foo', $record)->andReturnTrue();

        $this->assertSame('bar', (new StackStore([$top, $middle, $bottom]))->get('foo'));
    }

    public function testConstructorRequiresAtLeastOneStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A cache stack requires at least one store layer.');

        new StackStore([]);
    }

    public function testPutWithCorrectTTL()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(50));

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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
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

    public function testNullSentinelPropagatesThroughStackedStores()
    {
        $stack = new StackStore([
            new ArrayStore(serializesValues: true),
            new ArrayStore(serializesValues: true),
        ]);
        $repo = new Repository($stack);

        $result1 = $repo->rememberNullable('k', 60, fn () => null);
        $this->assertNull($result1);

        // Stack-level get unwraps the record and returns the stored sentinel.
        $this->assertSame(NullSentinel::VALUE, $stack->get('k'));

        // Second remember call: callback must not re-run — sentinel recognized as hit.
        $invoked = false;
        $result2 = $repo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result2);
        $this->assertFalse($invoked);
    }

    public function testPutItemToStoreStacked()
    {
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
        $record = compact('value', 'expiration');

        $this->swoole->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, $record, $ttl)->andReturn(false);
        $this->swoole->shouldReceive('forget')->once()->with($key)->andReturn(true);

        $this->assertFalse($this->store->put($key, $value, $ttl));
    }

    public function testPutCompensatesEarlierLayerWhenLowerLayerThrows(): void
    {
        $top = m::mock(ArrayStore::class);
        $middle = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->shouldReceive('put')->once()->andReturnTrue();
        $middle->shouldReceive('put')->once()->andThrow($exception);
        $bottom->shouldNotReceive('put');
        $top->shouldReceive('forget')->once()->with('foo')->andReturnTrue();

        try {
            (new StackStore([$top, $middle, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the lower-layer exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testPutDoesNotCompensateLayerWhoseWriteThrows(): void
    {
        $top = m::mock(ArrayStore::class);
        $bottom = m::mock(ArrayStore::class);
        $exception = new RuntimeException('write failed');

        $top->shouldReceive('put')->once()->andThrow($exception);
        $top->shouldNotReceive('forget');
        $bottom->shouldNotReceive('put');

        try {
            (new StackStore([$top, $bottom]))->put('foo', 'bar', 60);
            $this->fail('Expected the current-layer exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('put')->once()->with('foo', ['value' => 'bar', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with('foo', ['value' => 'bar', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->swoole->shouldReceive('put')->once()->with('bar', ['value' => 'baz', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with('bar', ['value' => 'baz', 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertTrue($this->store->putMany(['foo' => 'bar', 'bar' => 'baz'], $ttl));
    }

    public function testPutManyReturnsTrueForEmptyInput()
    {
        $this->createStores();

        $this->assertTrue($this->store->putMany([], 100));
    }

    public function testPutManyReturnsFalseForFailedKeyAndAttemptsLaterKeys()
    {
        $this->createStores();

        $ttl = 100;
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('put')->once()->with('first', ['value' => 'one', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with('first', ['value' => 'one', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->swoole->shouldReceive('put')->once()->with('fail', ['value' => 'two', 'expiration' => $expiration], $ttl)->andReturn(false);
        $this->redis->shouldNotReceive('put')->with('fail', m::any(), m::any());
        $this->swoole->shouldReceive('put')->once()->with('after', ['value' => 'three', 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with('after', ['value' => 'three', 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertFalse($this->store->putMany([
            'first' => 'one',
            'fail' => 'two',
            'after' => 'three',
        ], $ttl));
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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn(['value' => 1, 'expiration' => $expiration]);
        $this->swoole->shouldReceive('put')->once()->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, ['value' => 2, 'expiration' => $expiration], $ttl)->andReturn(true);

        $this->assertSame(2, $this->store->increment($key));
    }

    public function testIncrementDoesNotTreatFailedRepairAsMissingAndPreservesLowerValue(): void
    {
        $this->createStores();

        $record = ['value' => 5];
        $incrementedRecord = ['value' => 6];

        $this->swoole->shouldReceive('get')->twice()->with('counter')->andReturnNull();
        $this->redis->shouldReceive('get')->twice()->with('counter')->andReturn($record);
        $this->swoole->shouldReceive('forever')->twice()->with('counter', $record)->andReturnFalse();

        $this->swoole->shouldReceive('forever')->once()->with('counter', $incrementedRecord)->andReturnTrue();
        $this->redis->shouldReceive('forever')->once()->with('counter', $incrementedRecord)->andReturnFalse();
        $this->swoole->shouldReceive('forget')->once()->with('counter')->andReturnTrue();

        $this->assertFalse($this->store->increment('counter'));
        $this->assertSame(5, $this->store->get('counter'));
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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;

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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
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
        $expiration = CarbonImmutable::now()->getTimestamp() + $ttl;
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

    public function testTouchPropagatesThroughAllLayers()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $this->createStores();

        $key = 'foo';
        $value = 'bar';
        $record = ['value' => $value, 'ttl' => 30];

        $this->swoole->shouldReceive('get')->once()->with($key)->andReturn($record);
        $this->swoole->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->andReturn(true);
        $this->redis->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->andReturn(true);

        $this->assertTrue($this->store->touch($key, 60));
    }

    public function testTouchReturnsFalseWhenKeyDoesNotExist()
    {
        $this->createStores();

        $this->swoole->shouldReceive('get')->once()->with('nonexistent')->andReturn(null);
        $this->redis->shouldReceive('get')->once()->with('nonexistent')->andReturn(null);

        $this->assertFalse($this->store->touch('nonexistent', 60));
    }

    public function testTouchProxyCapsMaxTTL()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        /** @var MockInterface|SwooleStore $swoole */
        $swoole = m::mock(SwooleStore::class);
        /** @var MockInterface|RedisStore $redis */
        $redis = m::mock(RedisStore::class);

        $key = 'foo';
        $value = 'bar';
        $maxTTL = 3;
        $record = ['value' => $value, 'ttl' => 30];

        $store = new StackStore([
            new StackStoreProxy($swoole, $maxTTL),
            new StackStoreProxy($redis),
        ]);

        $swoole->shouldReceive('get')->once()->with($key)->andReturn($record);
        $swoole->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), $maxTTL)->andReturn(true);
        $redis->shouldReceive('put')->once()->with($key, m::on(fn ($r) => $r['value'] === $value && isset($r['expiration'])), 60)->andReturn(true);

        $this->assertTrue($store->touch($key, 60));
    }

    private function createStores()
    {
        $this->redis = m::mock(RedisStore::class);
        $this->swoole = m::mock(SwooleStore::class);
        $this->store = new StackStore([$this->swoole, $this->redis]);
    }
}
