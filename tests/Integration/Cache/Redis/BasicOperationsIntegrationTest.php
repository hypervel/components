<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;
use stdClass;

/**
 * Integration tests for basic cache operations.
 *
 * Tests core cache functionality (put, get, forget, has, add, increment, decrement, forever)
 * for both tag modes to verify they work correctly against real Redis.
 *
 * @internal
 * @coversNothing
 */
class BasicOperationsIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // BASIC OPERATIONS (NO TAGS) - BOTH MODES
    // =========================================================================

    public function testPutAndGetInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::put('basic_key', 'basic_value', 60);

        $this->assertSame('basic_value', Cache::get('basic_key'));
    }

    public function testPutAndGetInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::put('basic_key', 'basic_value', 60);

        $this->assertSame('basic_value', Cache::get('basic_key'));
    }

    public function testForgetInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::put('forget_key', 'forget_value', 60);
        $this->assertSame('forget_value', Cache::get('forget_key'));

        Cache::forget('forget_key');
        $this->assertNull(Cache::get('forget_key'));
    }

    public function testForgetInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::put('forget_key', 'forget_value', 60);
        $this->assertSame('forget_value', Cache::get('forget_key'));

        Cache::forget('forget_key');
        $this->assertNull(Cache::get('forget_key'));
    }

    public function testHasInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        $this->assertFalse(Cache::has('has_key'));

        Cache::put('has_key', 'has_value', 60);
        $this->assertTrue(Cache::has('has_key'));

        Cache::forget('has_key');
        $this->assertFalse(Cache::has('has_key'));
    }

    public function testHasInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        $this->assertFalse(Cache::has('has_key'));

        Cache::put('has_key', 'has_value', 60);
        $this->assertTrue(Cache::has('has_key'));

        Cache::forget('has_key');
        $this->assertFalse(Cache::has('has_key'));
    }

    public function testAddInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        // Add to non-existent key should succeed
        $result = Cache::add('add_key', 'first_value', 60);
        $this->assertTrue($result);
        $this->assertSame('first_value', Cache::get('add_key'));

        // Add to existing key should fail
        $result = Cache::add('add_key', 'second_value', 60);
        $this->assertFalse($result);
        $this->assertSame('first_value', Cache::get('add_key'));
    }

    public function testAddInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        // Add to non-existent key should succeed
        $result = Cache::add('add_key', 'first_value', 60);
        $this->assertTrue($result);
        $this->assertSame('first_value', Cache::get('add_key'));

        // Add to existing key should fail
        $result = Cache::add('add_key', 'second_value', 60);
        $this->assertFalse($result);
        $this->assertSame('first_value', Cache::get('add_key'));
    }

    public function testIncrementInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::put('counter', 10, 60);

        $result = Cache::increment('counter', 5);
        $this->assertEquals(15, $result);
        $this->assertEquals(15, Cache::get('counter'));

        $result = Cache::increment('counter');
        $this->assertEquals(16, $result);
    }

    public function testIncrementInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::put('counter', 10, 60);

        $result = Cache::increment('counter', 5);
        $this->assertEquals(15, $result);
        $this->assertEquals(15, Cache::get('counter'));

        $result = Cache::increment('counter');
        $this->assertEquals(16, $result);
    }

    public function testDecrementInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::put('counter', 10, 60);

        $result = Cache::decrement('counter', 3);
        $this->assertEquals(7, $result);
        $this->assertEquals(7, Cache::get('counter'));

        $result = Cache::decrement('counter');
        $this->assertEquals(6, $result);
    }

    public function testDecrementInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::put('counter', 10, 60);

        $result = Cache::decrement('counter', 3);
        $this->assertEquals(7, $result);
        $this->assertEquals(7, Cache::get('counter'));

        $result = Cache::decrement('counter');
        $this->assertEquals(6, $result);
    }

    public function testForeverInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::forever('eternal_key', 'eternal_value');

        $this->assertSame('eternal_value', Cache::get('eternal_key'));

        // Verify TTL is -1 (no expiration)
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'eternal_key');
        $this->assertEquals(-1, $ttl);
    }

    public function testForeverInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::forever('eternal_key', 'eternal_value');

        $this->assertSame('eternal_value', Cache::get('eternal_key'));

        // Verify TTL is -1 (no expiration)
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'eternal_key');
        $this->assertEquals(-1, $ttl);
    }

    // =========================================================================
    // BASIC OPERATIONS WITH TAGS - ALL MODE
    // =========================================================================

    public function testTaggedPutAndGetInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts', 'user:1'])->put('post:1', 'Post content', 60);

        // In all mode, must retrieve with same tags
        $this->assertSame('Post content', Cache::tags(['posts', 'user:1'])->get('post:1'));
    }

    public function testTaggedForgetInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->put('post:1', 'content', 60);
        $this->assertSame('content', Cache::tags(['posts'])->get('post:1'));

        Cache::tags(['posts'])->forget('post:1');
        $this->assertNull(Cache::tags(['posts'])->get('post:1'));
    }

    public function testTaggedAddInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['users'])->add('user:1', 'John', 60);
        $this->assertTrue($result);
        $this->assertSame('John', Cache::tags(['users'])->get('user:1'));

        $result = Cache::tags(['users'])->add('user:1', 'Jane', 60);
        $this->assertFalse($result);
        $this->assertSame('John', Cache::tags(['users'])->get('user:1'));
    }

    public function testTaggedIncrementInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['counters'])->put('views', 10, 60);

        $result = Cache::tags(['counters'])->increment('views', 5);
        $this->assertEquals(15, $result);
        $this->assertEquals(15, Cache::tags(['counters'])->get('views'));
    }

    public function testTaggedDecrementInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['counters'])->put('views', 10, 60);

        $result = Cache::tags(['counters'])->decrement('views', 3);
        $this->assertEquals(7, $result);
        $this->assertEquals(7, Cache::tags(['counters'])->get('views'));
    }

    public function testTaggedForeverInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->forever('eternal_post', 'Forever content');

        $this->assertSame('Forever content', Cache::tags(['posts'])->get('eternal_post'));
    }

    // =========================================================================
    // BASIC OPERATIONS WITH TAGS - ANY MODE
    // =========================================================================

    public function testTaggedPutAndGetInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'user:1'])->put('post:1', 'Post content', 60);

        // In any mode, can retrieve WITHOUT tags
        $this->assertSame('Post content', Cache::get('post:1'));
    }

    public function testTaggedForgetDirectlyInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post:1', 'content', 60);
        $this->assertSame('content', Cache::get('post:1'));

        // In any mode, can forget directly (without tags)
        Cache::forget('post:1');
        $this->assertNull(Cache::get('post:1'));
    }

    public function testTaggedAddInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['users'])->add('user:1', 'John', 60);
        $this->assertTrue($result);
        $this->assertSame('John', Cache::get('user:1'));

        // Add should fail because key exists (checked by key, not by tags)
        $result = Cache::tags(['users'])->add('user:1', 'Jane', 60);
        $this->assertFalse($result);
        $this->assertSame('John', Cache::get('user:1'));
    }

    public function testTaggedIncrementInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['counters'])->put('views', 10, 60);

        $result = Cache::tags(['counters'])->increment('views', 5);
        $this->assertEquals(15, $result);
        $this->assertEquals(15, Cache::get('views'));
    }

    public function testTaggedDecrementInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['counters'])->put('views', 10, 60);

        $result = Cache::tags(['counters'])->decrement('views', 3);
        $this->assertEquals(7, $result);
        $this->assertEquals(7, Cache::get('views'));
    }

    public function testTaggedForeverInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->forever('eternal_post', 'Forever content');

        // In any mode, can retrieve WITHOUT tags
        $this->assertSame('Forever content', Cache::get('eternal_post'));
    }

    // =========================================================================
    // DATA TYPES AND VALUES
    // =========================================================================

    public function testStoresVariousDataTypesInAllMode(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertDataTypesStoredCorrectly();
    }

    public function testStoresVariousDataTypesInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertDataTypesStoredCorrectly();
    }

    public function testStoresVariousDataTypesWithTagsInAllMode(): void
    {
        $this->setTagMode(TagMode::All);
        $this->assertDataTypesStoredCorrectlyWithTags();
    }

    public function testStoresVariousDataTypesWithTagsInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);
        $this->assertDataTypesStoredCorrectlyWithTags();
    }

    // =========================================================================
    // MANY OPERATIONS
    // =========================================================================

    public function testPutManyAndManyInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::putMany([
            'many_key1' => 'value1',
            'many_key2' => 'value2',
            'many_key3' => 'value3',
        ], 60);

        $result = Cache::many(['many_key1', 'many_key2', 'many_key3', 'nonexistent']);

        $this->assertSame('value1', $result['many_key1']);
        $this->assertSame('value2', $result['many_key2']);
        $this->assertSame('value3', $result['many_key3']);
        $this->assertNull($result['nonexistent']);
    }

    public function testPutManyAndManyInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::putMany([
            'many_key1' => 'value1',
            'many_key2' => 'value2',
            'many_key3' => 'value3',
        ], 60);

        $result = Cache::many(['many_key1', 'many_key2', 'many_key3', 'nonexistent']);

        $this->assertSame('value1', $result['many_key1']);
        $this->assertSame('value2', $result['many_key2']);
        $this->assertSame('value3', $result['many_key3']);
        $this->assertNull($result['nonexistent']);
    }

    public function testTaggedPutManyInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['batch'])->putMany([
            'batch:1' => 'value1',
            'batch:2' => 'value2',
        ], 60);

        $this->assertSame('value1', Cache::tags(['batch'])->get('batch:1'));
        $this->assertSame('value2', Cache::tags(['batch'])->get('batch:2'));
    }

    public function testTaggedPutManyInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['batch'])->putMany([
            'batch:1' => 'value1',
            'batch:2' => 'value2',
        ], 60);

        // In any mode, retrieve without tags
        $this->assertSame('value1', Cache::get('batch:1'));
        $this->assertSame('value2', Cache::get('batch:2'));
    }

    // =========================================================================
    // FLUSH OPERATIONS
    // =========================================================================

    public function testFlushInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::put('flush_key1', 'value1', 60);
        Cache::put('flush_key2', 'value2', 60);

        Cache::flush();

        $this->assertNull(Cache::get('flush_key1'));
        $this->assertNull(Cache::get('flush_key2'));
    }

    public function testFlushInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::put('flush_key1', 'value1', 60);
        Cache::put('flush_key2', 'value2', 60);

        Cache::flush();

        $this->assertNull(Cache::get('flush_key1'));
        $this->assertNull(Cache::get('flush_key2'));
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function assertDataTypesStoredCorrectly(): void
    {
        // String
        Cache::put('type_string', 'hello', 60);
        $this->assertSame('hello', Cache::get('type_string'));

        // Integer
        Cache::put('type_int', 42, 60);
        $this->assertEquals(42, Cache::get('type_int'));

        // Float
        Cache::put('type_float', 3.14, 60);
        $this->assertEquals(3.14, Cache::get('type_float'));

        // Boolean true
        Cache::put('type_bool_true', true, 60);
        $this->assertTrue(Cache::get('type_bool_true'));

        // Boolean false
        Cache::put('type_bool_false', false, 60);
        $this->assertFalse(Cache::get('type_bool_false'));

        // Null
        Cache::put('type_null', null, 60);
        $this->assertNull(Cache::get('type_null'));

        // Array
        Cache::put('type_array', ['a' => 1, 'b' => 2], 60);
        $this->assertEquals(['a' => 1, 'b' => 2], Cache::get('type_array'));

        // Object (as array after serialization)
        $obj = new stdClass();
        $obj->name = 'test';
        Cache::put('type_object', $obj, 60);
        $retrieved = Cache::get('type_object');
        $this->assertEquals('test', $retrieved->name);

        // Zero
        Cache::put('type_zero', 0, 60);
        $this->assertEquals(0, Cache::get('type_zero'));

        // Empty string
        Cache::put('type_empty_string', '', 60);
        $this->assertSame('', Cache::get('type_empty_string'));

        // Empty array
        Cache::put('type_empty_array', [], 60);
        $this->assertEquals([], Cache::get('type_empty_array'));
    }

    private function assertDataTypesStoredCorrectlyWithTags(): void
    {
        $tags = ['types'];
        $isAnyMode = $this->getTagMode()->isAnyMode();

        // Use a helper to get the value based on mode
        $get = fn (string $key) => $isAnyMode
            ? Cache::get($key)
            : Cache::tags($tags)->get($key);

        // String
        Cache::tags($tags)->put('type_string', 'hello', 60);
        $this->assertSame('hello', $get('type_string'));

        // Integer
        Cache::tags($tags)->put('type_int', 42, 60);
        $this->assertEquals(42, $get('type_int'));

        // Float
        Cache::tags($tags)->put('type_float', 3.14, 60);
        $this->assertEquals(3.14, $get('type_float'));

        // Boolean true
        Cache::tags($tags)->put('type_bool_true', true, 60);
        $this->assertTrue($get('type_bool_true'));

        // Boolean false
        Cache::tags($tags)->put('type_bool_false', false, 60);
        $this->assertFalse($get('type_bool_false'));

        // Array
        Cache::tags($tags)->put('type_array', ['a' => 1, 'b' => 2], 60);
        $this->assertEquals(['a' => 1, 'b' => 2], $get('type_array'));

        // Zero
        Cache::tags($tags)->put('type_zero', 0, 60);
        $this->assertEquals(0, $get('type_zero'));

        // Empty string
        Cache::tags($tags)->put('type_empty_string', '', 60);
        $this->assertSame('', $get('type_empty_string'));

        // Empty array
        Cache::tags($tags)->put('type_empty_array', [], 60);
        $this->assertEquals([], $get('type_empty_array'));
    }
}
