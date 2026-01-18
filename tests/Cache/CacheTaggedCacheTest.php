<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use DateInterval;
use DateTime;
use Hypervel\Cache\ArrayStore;
use Hypervel\Tests\TestCase;

enum TaggedCacheTestKeyStringEnum: string
{
    case Counter = 'counter';
    case Total = 'total';
}

enum TaggedCacheTestKeyIntEnum: int
{
    case Key1 = 1;
    case Key2 = 2;
}

enum TaggedCacheTestKeyUnitEnum
{
    case hits;
    case misses;
}

/**
 * @internal
 * @coversNothing
 */
class CacheTaggedCacheTest extends TestCase
{
    public function testCacheCanBeSavedWithMultipleTags()
    {
        $store = new ArrayStore();
        $tags = ['bop', 'zap'];
        $store->tags($tags)->put('foo', 'bar', 10);
        $this->assertSame('bar', $store->tags($tags)->get('foo'));
    }

    public function testCacheCanBeSetWithDatetimeArgument()
    {
        $store = new ArrayStore();
        $tags = ['bop', 'zap'];
        $duration = new DateTime();
        $duration->add(new DateInterval('PT10M'));
        $store->tags($tags)->put('foo', 'bar', $duration);
        $this->assertSame('bar', $store->tags($tags)->get('foo'));
    }

    public function testCacheSavedWithMultipleTagsCanBeFlushed()
    {
        $store = new ArrayStore();
        $tags1 = ['bop', 'zap'];
        $store->tags($tags1)->put('foo', 'bar', 10);
        $tags2 = ['bam', 'pow'];
        $store->tags($tags2)->put('foo', 'bar', 10);
        $store->tags('zap')->flush();
        $this->assertNull($store->tags($tags1)->get('foo'));
        $this->assertSame('bar', $store->tags($tags2)->get('foo'));
    }

    public function testTagsWithStringArgument()
    {
        $store = new ArrayStore();
        $store->tags('bop')->put('foo', 'bar', 10);
        $this->assertSame('bar', $store->tags('bop')->get('foo'));
    }

    public function testWithIncrement()
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        $taggableStore->put('foo', 5, 10);

        $value = $taggableStore->increment('foo');
        $this->assertSame(6, $value);

        $value = $taggableStore->increment('foo');
        $this->assertSame(7, $value);

        $value = $taggableStore->increment('foo', 3);
        $this->assertSame(10, $value);

        $value = $taggableStore->increment('foo', -2);
        $this->assertSame(8, $value);

        $value = $taggableStore->increment('x');
        $this->assertSame(1, $value);

        $value = $taggableStore->increment('y', 10);
        $this->assertSame(10, $value);
    }

    public function testWithDecrement()
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        $taggableStore->put('foo', 50, 10);

        $value = $taggableStore->decrement('foo');
        $this->assertSame(49, $value);

        $value = $taggableStore->decrement('foo');
        $this->assertSame(48, $value);

        $value = $taggableStore->decrement('foo', 3);
        $this->assertSame(45, $value);

        $value = $taggableStore->decrement('foo', -2);
        $this->assertSame(47, $value);

        $value = $taggableStore->decrement('x');
        $this->assertSame(-1, $value);

        $value = $taggableStore->decrement('y', 10);
        $this->assertSame(-10, $value);
    }

    public function testMany()
    {
        $store = $this->getTestCacheStoreWithTagValues();

        $values = $store->tags(['fruit'])->many(['a', 'e', 'b', 'd', 'c']);
        $this->assertSame([
            'a' => 'apple',
            'e' => null,
            'b' => 'banana',
            'd' => null,
            'c' => 'orange',
        ], $values);
    }

    public function testManyWithDefaultValues()
    {
        $store = $this->getTestCacheStoreWithTagValues();

        $values = $store->tags(['fruit'])->many([
            'a' => 147,
            'e' => 547,
            'b' => 'hello world!',
            'x' => 'hello world!',
            'd',
            'c',
        ]);
        $this->assertSame([
            'a' => 'apple',
            'e' => 547,
            'b' => 'banana',
            'x' => 'hello world!',
            'd' => null,
            'c' => 'orange',
        ], $values);
    }

    public function testGetMultiple()
    {
        $store = $this->getTestCacheStoreWithTagValues();

        $values = $store->tags(['fruit'])->getMultiple(['a', 'e', 'b', 'd', 'c']);
        $this->assertSame([
            'a' => 'apple',
            'e' => null,
            'b' => 'banana',
            'd' => null,
            'c' => 'orange',
        ], $values);

        $values = $store->tags(['fruit', 'color'])->getMultiple(['a', 'e', 'b', 'd', 'c']);
        $this->assertSame([
            'a' => 'red',
            'e' => 'blue',
            'b' => null,
            'd' => 'yellow',
            'c' => null,
        ], $values);
    }

    public function testGetMultipleWithDefaultValue()
    {
        $store = $this->getTestCacheStoreWithTagValues();

        $values = $store->tags(['fruit', 'color'])->getMultiple(['a', 'e', 'b', 'd', 'c'], 547);
        $this->assertSame([
            'a' => 'red',
            'e' => 'blue',
            'b' => 547,
            'd' => 'yellow',
            'c' => 547,
        ], $values);
    }

    public function testTagsWithIncrementCanBeFlushed()
    {
        $store = new ArrayStore();
        $store->tags('bop')->increment('foo', 5);
        $this->assertEquals(5, $store->tags('bop')->get('foo'));
        $store->tags('bop')->flush();
        $this->assertNull($store->tags('bop')->get('foo'));
    }

    public function testTagsWithDecrementCanBeFlushed()
    {
        $store = new ArrayStore();
        $store->tags('bop')->decrement('foo', 5);
        $this->assertEquals(-5, $store->tags('bop')->get('foo'));
        $store->tags('bop')->flush();
        $this->assertNull($store->tags('bop')->get('foo'));
    }

    public function testTagsCacheForever()
    {
        $store = new ArrayStore();
        $tags = ['bop', 'zap'];
        $store->tags($tags)->forever('foo', 'bar');
        $this->assertSame('bar', $store->tags($tags)->get('foo'));
    }

    public function testIncrementAcceptsStringBackedEnum(): void
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        $taggableStore->put(TaggedCacheTestKeyStringEnum::Counter, 5, 10);

        $value = $taggableStore->increment(TaggedCacheTestKeyStringEnum::Counter);

        $this->assertSame(6, $value);
        $this->assertSame(6, $taggableStore->get('counter'));
    }

    public function testIncrementAcceptsUnitEnum(): void
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        $taggableStore->put('hits', 10, 10);

        $value = $taggableStore->increment(TaggedCacheTestKeyUnitEnum::hits);

        $this->assertSame(11, $value);
    }

    public function testIncrementWithIntBackedEnumThrowsTypeError(): void
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        // Int-backed enum causes TypeError because itemKey() expects string
        $this->expectException(\TypeError::class);
        $taggableStore->increment(TaggedCacheTestKeyIntEnum::Key1);
    }

    public function testDecrementAcceptsStringBackedEnum(): void
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        $taggableStore->put(TaggedCacheTestKeyStringEnum::Counter, 50, 10);

        $value = $taggableStore->decrement(TaggedCacheTestKeyStringEnum::Counter);

        $this->assertSame(49, $value);
        $this->assertSame(49, $taggableStore->get('counter'));
    }

    public function testDecrementAcceptsUnitEnum(): void
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        $taggableStore->put('misses', 20, 10);

        $value = $taggableStore->decrement(TaggedCacheTestKeyUnitEnum::misses);

        $this->assertSame(19, $value);
    }

    public function testDecrementWithIntBackedEnumThrowsTypeError(): void
    {
        $store = new ArrayStore();
        $taggableStore = $store->tags('bop');

        // Int-backed enum causes TypeError because itemKey() expects string
        $this->expectException(\TypeError::class);
        $taggableStore->decrement(TaggedCacheTestKeyIntEnum::Key1);
    }

    private function getTestCacheStoreWithTagValues(): ArrayStore
    {
        $store = new ArrayStore();

        $tags = ['fruit'];
        $store->tags($tags)->put('a', 'apple', 10);
        $store->tags($tags)->put('b', 'banana', 10);
        $store->tags($tags)->put('c', 'orange', 10);

        $tags = ['fruit', 'color'];
        $store->tags($tags)->putMany([
            'a' => 'red',
            'd' => 'yellow',
            'e' => 'blue',
        ], 10);

        $tags = ['sizes', 'shirt'];
        $store->tags($tags)->putMany([
            'a' => 'small',
            'b' => 'medium',
            'c' => 'large',
        ], 10);

        return $store;
    }
}
