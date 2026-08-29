<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheNullStoreTest extends TestCase
{
    public function testItemsCanNotBeCached()
    {
        $store = new NullStore;
        $store->put('foo', 'bar', 10);
        $this->assertNull($store->get('foo'));
    }

    public function testGetMultipleReturnsMultipleNulls()
    {
        $store = new NullStore;

        $this->assertEquals([
            'foo' => null,
            'bar' => null,
        ], $store->many([
            'foo',
            'bar',
        ]));
    }

    public function testIncrementAndDecrementReturnFalse()
    {
        $store = new NullStore;
        $this->assertFalse($store->increment('foo'));
        $this->assertFalse($store->decrement('foo'));
    }

    public function testTouchReturnsFalse()
    {
        $this->assertFalse((new NullStore)->touch('foo', 30));
    }

    public function testRememberNullableAlwaysReRunsCallbackOnNullStore(): void
    {
        $repo = new Repository(new NullStore);

        $count = 0;
        $repo->rememberNullable('k', 60, function () use (&$count) {
            ++$count;
            return null;
        });
        $repo->rememberNullable('k', 60, function () use (&$count) {
            ++$count;
            return null;
        });

        $this->assertSame(2, $count);
    }

    public function testTaggedFlushReportsRejectedTagIdentifierWrites(): void
    {
        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $events->shouldReceive('dispatch')->andReturnUsing(function (object $event) use (&$captured): void {
            $captured[] = $event;
        });

        $cache = (new Repository(new NullStore, ['store' => 'null']))->tags(['users', 'posts']);
        $cache->setEventDispatcher($events);

        $this->assertFalse($cache->flush());
        $this->assertSame([CacheFlushing::class, CacheFlushFailed::class], array_map(get_class(...), $captured));
        $this->assertSame('null', $captured[1]->storeName);
        $this->assertSame(['users', 'posts'], $captured[1]->tags);
    }

    public function testLocksCanBeFlushed(): void
    {
        $store = new NullStore;

        $this->assertInstanceOf(CanFlushLocks::class, $store);
        $this->assertTrue($store->supportsFlushingLocks());
        $this->assertTrue($store->flushLocks());
        $this->assertFalse($store->hasSeparateLockStore());
    }
}
