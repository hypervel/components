<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for tagged cache operations.
 *
 * Verifies that tag data structures are created correctly:
 * - All mode: ZSET with timestamp scores
 * - Any mode: HASH with field expiration, reverse index SET, registry ZSET
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class TaggedOperationsIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // ALL MODE - TAG STRUCTURE VERIFICATION
    // =========================================================================

    public function testAllModeCreatesZsetForTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->put('post:1', 'content', 60);

        // Verify the ZSET exists
        $tagKey = $this->allModeTagKey('posts');
        $type = $this->redis()->type($tagKey);

        $this->assertEquals(\Redis::REDIS_ZSET, $type, 'Tag structure should be a ZSET in all mode');
    }

    public function testAllModeStoresNamespacedKeyInZset(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->put('post:1', 'content', 60);

        // Get the entries from the ZSET
        $entries = $this->getAllModeTagEntries('posts');

        $this->assertNotEmpty($entries, 'ZSET should contain entries');

        // The key stored is the namespaced key (sha1 of tag names + key)
        // We can't predict the exact key, but we can verify an entry exists
        $this->assertCount(1, $entries);
    }

    public function testAllModeZsetScoreIsTimestamp(): void
    {
        $this->setTagMode(TagMode::All);

        $before = time();
        Cache::tags(['posts'])->put('post:1', 'content', 60);
        $after = time();

        $entries = $this->getAllModeTagEntries('posts');
        $score = (int) reset($entries);

        // Score should be the expiration timestamp
        $this->assertGreaterThanOrEqual($before + 60, $score);
        $this->assertLessThanOrEqual($after + 60 + 1, $score);
    }

    public function testAllModeMultipleTagsCreateMultipleZsets(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts', 'featured'])->put('post:1', 'content', 60);

        // Both ZSETs should exist
        $this->assertRedisKeyExists($this->allModeTagKey('posts'));
        $this->assertRedisKeyExists($this->allModeTagKey('featured'));

        // Both should contain the entry
        $this->assertCount(1, $this->getAllModeTagEntries('posts'));
        $this->assertCount(1, $this->getAllModeTagEntries('featured'));
    }

    public function testAllModeForeverUsesNegativeOneScore(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->forever('eternal', 'content');

        $entries = $this->getAllModeTagEntries('posts');
        $score = (int) reset($entries);

        // Forever items use score -1 (won't be cleaned by ZREMRANGEBYSCORE)
        $this->assertEquals(-1, $score);
    }

    public function testAllModeNamespaceIsolatesTagSets(): void
    {
        $this->setTagMode(TagMode::All);

        // Same key, different tags - should be isolated
        Cache::tags(['tag1'])->put('key', 'value1', 60);
        Cache::tags(['tag2'])->put('key', 'value2', 60);

        // Both values should be accessible with their respective tags
        $this->assertSame('value1', Cache::tags(['tag1'])->get('key'));
        $this->assertSame('value2', Cache::tags(['tag2'])->get('key'));
    }

    // =========================================================================
    // ANY MODE - TAG STRUCTURE VERIFICATION
    // =========================================================================

    public function testAnyModeCreatesHashForTags(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post:1', 'content', 60);

        // Verify the HASH exists
        $tagKey = $this->anyModeTagKey('posts');
        $type = $this->redis()->type($tagKey);

        $this->assertEquals(\Redis::REDIS_HASH, $type, 'Tag structure should be a HASH in any mode');
    }

    public function testAnyModeStoresCacheKeyAsHashField(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post:1', 'content', 60);

        // Verify the cache key is stored as a field in the hash
        $this->assertTrue(
            $this->anyModeTagHasEntry('posts', 'post:1'),
            'Cache key should be stored as hash field'
        );
    }

    public function testAnyModeCreatesReverseIndex(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'featured'])->put('post:1', 'content', 60);

        // Verify reverse index SET exists
        $reverseKey = $this->anyModeReverseIndexKey('post:1');
        $type = $this->redis()->type($reverseKey);

        $this->assertEquals(\Redis::REDIS_SET, $type, 'Reverse index should be a SET');

        // Verify it contains both tags
        $tags = $this->getAnyModeReverseIndex('post:1');
        $this->assertContains('posts', $tags);
        $this->assertContains('featured', $tags);
    }

    public function testAnyModeUpdatesRegistry(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'featured'])->put('post:1', 'content', 60);

        // Verify registry contains both tags
        $this->assertTrue($this->anyModeRegistryHasTag('posts'));
        $this->assertTrue($this->anyModeRegistryHasTag('featured'));
    }

    public function testAnyModeRegistryScoreIsExpiryTimestamp(): void
    {
        $this->setTagMode(TagMode::Any);

        $before = time();
        Cache::tags(['posts'])->put('post:1', 'content', 60);
        $after = time();

        $registry = $this->getAnyModeRegistry();
        $this->assertArrayHasKey('posts', $registry);

        $score = (int) $registry['posts'];

        // Score should be the expiry timestamp (current time + TTL)
        $this->assertGreaterThanOrEqual($before + 60, $score);
        $this->assertLessThanOrEqual($after + 60 + 1, $score);
    }

    public function testAnyModeMultipleTagsCreateMultipleHashes(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'featured'])->put('post:1', 'content', 60);

        // Both HASHes should exist
        $this->assertRedisKeyExists($this->anyModeTagKey('posts'));
        $this->assertRedisKeyExists($this->anyModeTagKey('featured'));

        // Both should contain the cache key
        $this->assertTrue($this->anyModeTagHasEntry('posts', 'post:1'));
        $this->assertTrue($this->anyModeTagHasEntry('featured', 'post:1'));
    }

    public function testAnyModeDirectAccessWithoutTags(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post:1', 'content', 60);

        // In any mode, can access directly without tags
        $this->assertSame('content', Cache::get('post:1'));
    }

    public function testAnyModeSameKeyDifferentTagsOverwrites(): void
    {
        $this->setTagMode(TagMode::Any);

        // First put with tag1
        Cache::tags(['tag1'])->put('key', 'value1', 60);
        $this->assertSame('value1', Cache::get('key'));
        $this->assertTrue($this->anyModeTagHasEntry('tag1', 'key'));

        // Second put with tag2 - should overwrite value AND update tags
        Cache::tags(['tag2'])->put('key', 'value2', 60);
        $this->assertSame('value2', Cache::get('key'));

        // Reverse index should now contain tag2
        $tags = $this->getAnyModeReverseIndex('key');
        $this->assertContains('tag2', $tags);
    }

    // =========================================================================
    // BOTH MODES - MULTIPLE ITEMS SAME TAG
    // =========================================================================

    public function testAllModeMultipleItemsSameTag(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->put('post:1', 'content1', 60);
        Cache::tags(['posts'])->put('post:2', 'content2', 60);
        Cache::tags(['posts'])->put('post:3', 'content3', 60);

        // All should be accessible
        $this->assertSame('content1', Cache::tags(['posts'])->get('post:1'));
        $this->assertSame('content2', Cache::tags(['posts'])->get('post:2'));
        $this->assertSame('content3', Cache::tags(['posts'])->get('post:3'));

        // ZSET should have 3 entries
        $entries = $this->getAllModeTagEntries('posts');
        $this->assertCount(3, $entries);
    }

    public function testAnyModeMultipleItemsSameTag(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post:1', 'content1', 60);
        Cache::tags(['posts'])->put('post:2', 'content2', 60);
        Cache::tags(['posts'])->put('post:3', 'content3', 60);

        // All should be accessible directly
        $this->assertSame('content1', Cache::get('post:1'));
        $this->assertSame('content2', Cache::get('post:2'));
        $this->assertSame('content3', Cache::get('post:3'));

        // HASH should have 3 fields
        $entries = $this->getAnyModeTagEntries('posts');
        $this->assertCount(3, $entries);
        $this->assertArrayHasKey('post:1', $entries);
        $this->assertArrayHasKey('post:2', $entries);
        $this->assertArrayHasKey('post:3', $entries);
    }

    // =========================================================================
    // BOTH MODES - ITEM WITH MULTIPLE TAGS
    // =========================================================================

    public function testAllModeItemWithMultipleTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts', 'user:1', 'featured'])->put('post:1', 'content', 60);

        // All tag ZSETs should have the entry
        $this->assertCount(1, $this->getAllModeTagEntries('posts'));
        $this->assertCount(1, $this->getAllModeTagEntries('user:1'));
        $this->assertCount(1, $this->getAllModeTagEntries('featured'));
    }

    public function testAnyModeItemWithMultipleTags(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'user:1', 'featured'])->put('post:1', 'content', 60);

        // All tag HASHes should have the entry
        $this->assertTrue($this->anyModeTagHasEntry('posts', 'post:1'));
        $this->assertTrue($this->anyModeTagHasEntry('user:1', 'post:1'));
        $this->assertTrue($this->anyModeTagHasEntry('featured', 'post:1'));

        // Reverse index should have all tags
        $tags = $this->getAnyModeReverseIndex('post:1');
        $this->assertCount(3, $tags);
        $this->assertContains('posts', $tags);
        $this->assertContains('user:1', $tags);
        $this->assertContains('featured', $tags);

        // Registry should have all tags
        $this->assertTrue($this->anyModeRegistryHasTag('posts'));
        $this->assertTrue($this->anyModeRegistryHasTag('user:1'));
        $this->assertTrue($this->anyModeRegistryHasTag('featured'));
    }

    // =========================================================================
    // OPERATIONS THAT UPDATE TAG STRUCTURES
    // =========================================================================

    public function testAllModeIncrementMaintainsTagStructure(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['counters'])->put('views', 10, 60);
        $this->assertCount(1, $this->getAllModeTagEntries('counters'));

        Cache::tags(['counters'])->increment('views', 5);

        // Tag structure should still exist
        $this->assertCount(1, $this->getAllModeTagEntries('counters'));
        $this->assertEquals(15, Cache::tags(['counters'])->get('views'));
    }

    public function testAnyModeIncrementMaintainsTagStructure(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['counters'])->put('views', 10, 60);
        $this->assertTrue($this->anyModeTagHasEntry('counters', 'views'));

        Cache::tags(['counters'])->increment('views', 5);

        // Tag structure should still exist
        $this->assertTrue($this->anyModeTagHasEntry('counters', 'views'));
        $this->assertEquals(15, Cache::get('views'));
    }

    public function testAllModeAddCreatesTagStructure(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['users'])->add('user:1', 'John', 60);

        $this->assertTrue($result);
        $this->assertCount(1, $this->getAllModeTagEntries('users'));
    }

    public function testAnyModeAddCreatesTagStructure(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['users'])->add('user:1', 'John', 60);

        $this->assertTrue($result);
        $this->assertTrue($this->anyModeTagHasEntry('users', 'user:1'));
        $this->assertContains('users', $this->getAnyModeReverseIndex('user:1'));
    }

    public function testAllModeForeverCreatesTagStructure(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['eternal'])->forever('forever_key', 'forever_value');

        $this->assertCount(1, $this->getAllModeTagEntries('eternal'));
    }

    public function testAnyModeForeverCreatesTagStructure(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['eternal'])->forever('forever_key', 'forever_value');

        $this->assertTrue($this->anyModeTagHasEntry('eternal', 'forever_key'));
        $this->assertContains('eternal', $this->getAnyModeReverseIndex('forever_key'));
    }

    // =========================================================================
    // PUTMANY WITH TAGS
    // =========================================================================

    public function testAllModePutManyCreatesTagStructure(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['batch'])->putMany([
            'item:1' => 'value1',
            'item:2' => 'value2',
            'item:3' => 'value3',
        ], 60);

        // Should have 3 entries in the ZSET
        $entries = $this->getAllModeTagEntries('batch');
        $this->assertCount(3, $entries);
    }

    public function testAnyModePutManyCreatesTagStructure(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['batch'])->putMany([
            'item:1' => 'value1',
            'item:2' => 'value2',
            'item:3' => 'value3',
        ], 60);

        // Should have 3 fields in the HASH
        $entries = $this->getAnyModeTagEntries('batch');
        $this->assertCount(3, $entries);
        $this->assertArrayHasKey('item:1', $entries);
        $this->assertArrayHasKey('item:2', $entries);
        $this->assertArrayHasKey('item:3', $entries);

        // Each item should have reverse index
        $this->assertContains('batch', $this->getAnyModeReverseIndex('item:1'));
        $this->assertContains('batch', $this->getAnyModeReverseIndex('item:2'));
        $this->assertContains('batch', $this->getAnyModeReverseIndex('item:3'));
    }

    public function testAllModeLargePutManyChunking(): void
    {
        $this->setTagMode(TagMode::All);

        $values = [];
        for ($i = 0; $i < 1500; ++$i) {
            $values["large_key_{$i}"] = "value_{$i}";
        }

        $result = Cache::tags(['large_batch'])->putMany($values, 60);
        $this->assertTrue($result);

        // Verify first and last items exist
        $this->assertSame('value_0', Cache::tags(['large_batch'])->get('large_key_0'));
        $this->assertSame('value_1499', Cache::tags(['large_batch'])->get('large_key_1499'));

        // Verify tag structure has all entries
        $entries = $this->getAllModeTagEntries('large_batch');
        $this->assertCount(1500, $entries);

        // Flush and verify
        Cache::tags(['large_batch'])->flush();
        $this->assertNull(Cache::tags(['large_batch'])->get('large_key_0'));
    }

    public function testAnyModeLargePutManyChunking(): void
    {
        $this->setTagMode(TagMode::Any);

        $values = [];
        for ($i = 0; $i < 1500; ++$i) {
            $values["large_key_{$i}"] = "value_{$i}";
        }

        $result = Cache::tags(['large_batch'])->putMany($values, 60);
        $this->assertTrue($result);

        // Verify first and last items exist
        $this->assertSame('value_0', Cache::get('large_key_0'));
        $this->assertSame('value_1499', Cache::get('large_key_1499'));

        // Verify tag structure has all entries
        $entries = $this->getAnyModeTagEntries('large_batch');
        $this->assertCount(1500, $entries);

        // Flush and verify
        Cache::tags(['large_batch'])->flush();
        $this->assertNull(Cache::get('large_key_0'));
    }

    public function testAnyModePutManyFlushByOneTag(): void
    {
        $this->setTagMode(TagMode::Any);

        $items = [
            'pm_key1' => 'value1',
            'pm_key2' => 'value2',
            'pm_key3' => 'value3',
        ];

        // Store with multiple tags
        Cache::tags(['pm_tag1', 'pm_tag2'])->putMany($items, 60);

        // Verify all exist
        $this->assertSame('value1', Cache::get('pm_key1'));
        $this->assertSame('value2', Cache::get('pm_key2'));
        $this->assertSame('value3', Cache::get('pm_key3'));

        // Flush only ONE of the tags - items should still be removed (any mode behavior)
        Cache::tags(['pm_tag1'])->flush();

        // All items should be gone because any mode removes items tagged with ANY of the flushed tags
        $this->assertNull(Cache::get('pm_key1'));
        $this->assertNull(Cache::get('pm_key2'));
        $this->assertNull(Cache::get('pm_key3'));
    }
}
