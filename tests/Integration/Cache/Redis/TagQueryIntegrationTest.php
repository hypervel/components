<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Generator;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for tag query operations (ANY MODE ONLY).
 *
 * Tests:
 * - getTaggedKeys() - retrieves all keys for a tag
 * - items() - retrieves key-value pairs for tags
 * - HKEYS vs HSCAN threshold behavior
 * - Deduplication of items with multiple tags
 * - Chunking for large datasets
 * - Handling of expired/missing keys
 *
 * @internal
 * @coversNothing
 */
class TagQueryIntegrationTest extends RedisCacheIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setTagMode(TagMode::Any);
    }

    // =========================================================================
    // getTaggedKeys() TESTS
    // =========================================================================

    public function testGetTaggedKeysReturnsEmptyForNonExistentTag(): void
    {
        $keys = $this->store()->anyTagOps()->getTaggedKeys()->execute('non_existent_tag_xyz');
        $result = iterator_to_array($keys);

        $this->assertSame([], $result);
    }

    public function testGetTaggedKeysReturnsAllKeysForTag(): void
    {
        Cache::tags(['test_tag'])->put('key1', 'value1', 60);
        Cache::tags(['test_tag'])->put('key2', 'value2', 60);
        Cache::tags(['test_tag'])->put('key3', 'value3', 60);

        $keys = $this->store()->anyTagOps()->getTaggedKeys()->execute('test_tag');
        $result = iterator_to_array($keys);

        $this->assertContains('key1', $result);
        $this->assertContains('key2', $result);
        $this->assertContains('key3', $result);
        $this->assertCount(3, $result);
    }

    public function testGetTaggedKeysHandlesSpecialCharacterKeys(): void
    {
        Cache::tags(['special_tag'])->put('key:with:colons', 'value1', 60);
        Cache::tags(['special_tag'])->put('key-with-dashes', 'value2', 60);
        Cache::tags(['special_tag'])->put('key_with_underscores', 'value3', 60);

        $keys = $this->store()->anyTagOps()->getTaggedKeys()->execute('special_tag');
        $result = iterator_to_array($keys);

        $this->assertContains('key:with:colons', $result);
        $this->assertContains('key-with-dashes', $result);
        $this->assertContains('key_with_underscores', $result);
    }

    public function testGetTaggedKeysReturnsGeneratorForSmallHashes(): void
    {
        // Create just a few items (below HSCAN threshold)
        for ($i = 0; $i < 5; ++$i) {
            Cache::tags(['small_tag'])->put("small_key_{$i}", "value_{$i}", 60);
        }

        // Always returns Generator (even for small hashes where HKEYS is used internally)
        $keys = $this->store()->anyTagOps()->getTaggedKeys()->execute('small_tag');

        $this->assertInstanceOf(Generator::class, $keys);
        $this->assertCount(5, iterator_to_array($keys));
    }

    // =========================================================================
    // items() TESTS
    // =========================================================================

    public function testItemsRetrievesAllItemsForSingleTag(): void
    {
        Cache::tags(['users'])->put('user:1', 'Alice', 60);
        Cache::tags(['users'])->put('user:2', 'Bob', 60);
        Cache::tags(['posts'])->put('post:1', 'Hello', 60);

        $items = iterator_to_array(Cache::tags(['users'])->items());

        $this->assertCount(2, $items);
        $this->assertArrayHasKey('user:1', $items);
        $this->assertArrayHasKey('user:2', $items);
        $this->assertSame('Alice', $items['user:1']);
        $this->assertSame('Bob', $items['user:2']);
        $this->assertArrayNotHasKey('post:1', $items);
    }

    public function testItemsRetrievesItemsForMultipleTagsUnion(): void
    {
        Cache::tags(['tag:a'])->put('item:a', 'A', 60);
        Cache::tags(['tag:b'])->put('item:b', 'B', 60);
        Cache::tags(['tag:c'])->put('item:c', 'C', 60);

        $items = iterator_to_array(Cache::tags(['tag:a', 'tag:b'])->items());

        $this->assertCount(2, $items);
        $this->assertArrayHasKey('item:a', $items);
        $this->assertArrayHasKey('item:b', $items);
        $this->assertSame('A', $items['item:a']);
        $this->assertSame('B', $items['item:b']);
        $this->assertArrayNotHasKey('item:c', $items);
    }

    public function testItemsDeduplicatesItemsWithMultipleTags(): void
    {
        // Item has both tags
        Cache::tags(['tag:1', 'tag:2'])->put('shared', 'Shared Value', 60);
        Cache::tags(['tag:1'])->put('unique:1', 'Unique 1', 60);

        // Retrieve items for both tags
        $items = iterator_to_array(Cache::tags(['tag:1', 'tag:2'])->items());

        $this->assertCount(2, $items);
        $this->assertArrayHasKey('shared', $items);
        $this->assertArrayHasKey('unique:1', $items);
        $this->assertSame('Shared Value', $items['shared']);
        $this->assertSame('Unique 1', $items['unique:1']);
    }

    public function testItemsHandlesLargeNumberWithChunking(): void
    {
        // Create 250 items (enough to test chunking)
        $data = [];
        for ($i = 0; $i < 250; ++$i) {
            $data["key:{$i}"] = "value:{$i}";
        }

        Cache::tags(['bulk'])->putMany($data, 60);

        $items = iterator_to_array(Cache::tags(['bulk'])->items());

        $this->assertCount(250, $items);
        $this->assertSame('value:0', $items['key:0']);
        $this->assertSame('value:249', $items['key:249']);
    }

    public function testItemsIgnoresExpiredOrMissingKeys(): void
    {
        Cache::tags(['temp'])->put('valid', 'value', 60);
        Cache::tags(['temp'])->put('expired', 'value', 60);

        // Manually delete 'expired' key in Redis but leave it in tag hash
        // (Simulating lazy cleanup state where tag entry still exists but key is gone)
        Cache::forget('expired');

        $items = iterator_to_array(Cache::tags(['temp'])->items());

        $this->assertCount(1, $items);
        $this->assertArrayHasKey('valid', $items);
        $this->assertSame('value', $items['valid']);
        $this->assertArrayNotHasKey('expired', $items);
    }

    public function testItemsReturnsEmptyForEmptyTag(): void
    {
        $items = iterator_to_array(Cache::tags(['empty'])->items());

        $this->assertEmpty($items);
    }

    // =========================================================================
    // items() RETURNS GENERATOR
    // =========================================================================

    public function testItemsReturnsGenerator(): void
    {
        Cache::tags(['gen_tag'])->put('key1', 'value1', 60);
        Cache::tags(['gen_tag'])->put('key2', 'value2', 60);

        $items = Cache::tags(['gen_tag'])->items();

        $this->assertInstanceOf(Generator::class, $items);
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testGetTaggedKeysWithForeverItems(): void
    {
        Cache::tags(['forever_tag'])->forever('forever1', 'value1');
        Cache::tags(['forever_tag'])->forever('forever2', 'value2');

        $keys = $this->store()->anyTagOps()->getTaggedKeys()->execute('forever_tag');
        $result = iterator_to_array($keys);

        $this->assertContains('forever1', $result);
        $this->assertContains('forever2', $result);
        $this->assertCount(2, $result);
    }

    public function testItemsWithMixedTtlItems(): void
    {
        Cache::tags(['mixed'])->put('short', 'short_value', 60);
        Cache::tags(['mixed'])->forever('forever', 'forever_value');

        $items = iterator_to_array(Cache::tags(['mixed'])->items());

        $this->assertCount(2, $items);
        $this->assertSame('short_value', $items['short']);
        $this->assertSame('forever_value', $items['forever']);
    }

    public function testItemsWithDifferentValueTypes(): void
    {
        Cache::tags(['types'])->put('string', 'hello', 60);
        Cache::tags(['types'])->put('int', 42, 60);
        Cache::tags(['types'])->put('array', ['a' => 1], 60);
        Cache::tags(['types'])->put('bool', true, 60);

        $items = iterator_to_array(Cache::tags(['types'])->items());

        $this->assertCount(4, $items);
        $this->assertSame('hello', $items['string']);
        $this->assertEquals(42, $items['int']);
        $this->assertEquals(['a' => 1], $items['array']);
        $this->assertTrue($items['bool']);
    }
}
