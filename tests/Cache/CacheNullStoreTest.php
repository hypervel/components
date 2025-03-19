<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\NullStore;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CacheNullStoreTest extends TestCase
{
    public function testItemsCanNotBeCached()
    {
        $store = new NullStore();
        $store->put('foo', 'bar', 10);
        $this->assertNull($store->get('foo'));
    }

    public function testGetMultipleReturnsMultipleNulls()
    {
        $store = new NullStore();

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
        $store = new NullStore();
        $this->assertFalse($store->increment('foo'));
        $this->assertFalse($store->decrement('foo'));
    }
}
