<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\NullStore;
use Hypervel\Cache\Repository;
use Hypervel\Tests\TestCase;

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
}
