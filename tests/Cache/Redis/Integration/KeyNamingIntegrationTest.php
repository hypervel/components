<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;
use Redis;

/**
 * Integration tests for key naming conventions.
 *
 * Verifies internal key structures are created correctly:
 * - All mode: {prefix}_all:tag:{name}:entries (ZSET)
 * - Any mode: {prefix}_any:tag:{name}:entries (HASH), {prefix}{key}:_any:tags (SET), {prefix}_any:tag:registry (ZSET)
 *
 * Also verifies collision prevention when tags have special names.
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class KeyNamingIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // ALL MODE - KEY STRUCTURE VERIFICATION
    // =========================================================================

    public function testAllModeTagKeyContainsAllSegment(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['products'])->put('item1', 'value', 60);

        // Find the tag ZSET key
        $tagKey = $this->allModeTagKey('products');
        $this->assertRedisKeyExists($tagKey);
        $this->assertKeyContainsSegment('_all:tag:', $tagKey);
        $this->assertKeyContainsSegment(':entries', $tagKey);
    }

    public function testAllModeCreatesCorrectKeyStructure(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['category'])->put('product123', 'data', 60);

        // In all mode, we should have:
        // 1. Cache value key (namespaced based on tags)
        // 2. Tag ZSET: {prefix}_all:tag:category:entries

        $tagZsetKey = $this->allModeTagKey('category');
        $this->assertRedisKeyExists($tagZsetKey);
        $this->assertEquals(Redis::REDIS_ZSET, $this->redis()->type($tagZsetKey));
    }

    public function testAllModeCreatesMultipleTagZsets(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts', 'featured', 'user:123'])->put('post1', 'content', 60);

        // Each tag should have its own ZSET
        $this->assertRedisKeyExists($this->allModeTagKey('posts'));
        $this->assertRedisKeyExists($this->allModeTagKey('featured'));
        $this->assertRedisKeyExists($this->allModeTagKey('user:123'));

        // All should be ZSET type
        $this->assertEquals(Redis::REDIS_ZSET, $this->redis()->type($this->allModeTagKey('posts')));
        $this->assertEquals(Redis::REDIS_ZSET, $this->redis()->type($this->allModeTagKey('featured')));
        $this->assertEquals(Redis::REDIS_ZSET, $this->redis()->type($this->allModeTagKey('user:123')));
    }

    public function testAllModeStoresNamespacedKeyInZset(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['mytag'])->put('mykey', 'value', 60);

        // In all mode, the ZSET stores the namespaced key (sha1 of tags + key)
        $entries = $this->getAllModeTagEntries('mytag');
        $this->assertCount(1, $entries);
    }

    public function testAllModeZsetScoreIsExpiryTimestamp(): void
    {
        $this->setTagMode(TagMode::All);

        $beforeTime = time();
        Cache::tags(['registrytest'])->put('key1', 'value', 3600);
        $afterTime = time();

        $entries = $this->getAllModeTagEntries('registrytest');
        $score = (int) reset($entries);

        // Score should be approximately now + 3600 seconds
        $expectedMin = $beforeTime + 3600;
        $expectedMax = $afterTime + 3600 + 1;

        $this->assertGreaterThanOrEqual($expectedMin, $score);
        $this->assertLessThanOrEqual($expectedMax, $score);
    }

    // =========================================================================
    // ANY MODE - KEY STRUCTURE VERIFICATION
    // =========================================================================

    public function testAnyModeTagKeyContainsAnySegment(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['products'])->put('item1', 'value', 60);

        // Find the tag HASH key
        $tagKey = $this->anyModeTagKey('products');
        $this->assertRedisKeyExists($tagKey);
        $this->assertKeyContainsSegment('_any:tag:', $tagKey);
        $this->assertKeyContainsSegment(':entries', $tagKey);
    }

    public function testAnyModeReverseIndexKeyContainsAnySegment(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['products'])->put('item1', 'value', 60);

        // Find the reverse index SET key
        $reverseKey = $this->anyModeReverseIndexKey('item1');
        $this->assertRedisKeyExists($reverseKey);
        $this->assertKeyContainsSegment(':_any:tags', $reverseKey);
    }

    public function testAnyModeRegistryKeyContainsAnySegment(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['products'])->put('item1', 'value', 60);

        // Find the registry ZSET key
        $registryKey = $this->anyModeRegistryKey();
        $this->assertRedisKeyExists($registryKey);
        $this->assertKeyContainsSegment('_any:tag:', $registryKey);
        $this->assertKeyContainsSegment('registry', $registryKey);
    }

    public function testAnyModeCreatesAllFourKeys(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['category'])->put('product123', 'data', 60);

        // In any mode, we should have exactly 4 keys:
        // 1. Cache value key: {prefix}product123
        // 2. Tag HASH: {prefix}_any:tag:category:entries
        // 3. Reverse index: {prefix}product123:_any:tags
        // 4. Registry: {prefix}_any:tag:registry

        $prefix = $this->getCachePrefix();

        $this->assertRedisKeyExists($prefix . 'product123');
        $this->assertRedisKeyExists($this->anyModeTagKey('category'));
        $this->assertRedisKeyExists($this->anyModeReverseIndexKey('product123'));
        $this->assertRedisKeyExists($this->anyModeRegistryKey());

        // Verify correct types
        $this->assertEquals(Redis::REDIS_STRING, $this->redis()->type($prefix . 'product123'));
        $this->assertEquals(Redis::REDIS_HASH, $this->redis()->type($this->anyModeTagKey('category')));
        $this->assertEquals(Redis::REDIS_SET, $this->redis()->type($this->anyModeReverseIndexKey('product123')));
        $this->assertEquals(Redis::REDIS_ZSET, $this->redis()->type($this->anyModeRegistryKey()));
    }

    public function testAnyModeCreatesMultipleTagHashes(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'featured', 'user:123'])->put('post1', 'content', 60);

        // Each tag should have its own HASH
        $this->assertRedisKeyExists($this->anyModeTagKey('posts'));
        $this->assertRedisKeyExists($this->anyModeTagKey('featured'));
        $this->assertRedisKeyExists($this->anyModeTagKey('user:123'));

        // All should be HASH type
        $this->assertEquals(Redis::REDIS_HASH, $this->redis()->type($this->anyModeTagKey('posts')));
        $this->assertEquals(Redis::REDIS_HASH, $this->redis()->type($this->anyModeTagKey('featured')));
        $this->assertEquals(Redis::REDIS_HASH, $this->redis()->type($this->anyModeTagKey('user:123')));
    }

    public function testAnyModeReverseIndexContainsTagNames(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['alpha', 'beta'])->put('mykey', 'value', 60);

        // Check the reverse index SET contains the tag names
        $tags = $this->getAnyModeReverseIndex('mykey');

        $this->assertContains('alpha', $tags);
        $this->assertContains('beta', $tags);
        $this->assertCount(2, $tags);
    }

    public function testAnyModeTagHashContainsCacheKey(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['mytag'])->put('mykey', 'value', 60);

        // Check the tag hash contains the cache key as a field
        $this->assertTrue($this->anyModeTagHasEntry('mytag', 'mykey'));

        // Verify the field value is '1' (our placeholder)
        $tagKey = $this->anyModeTagKey('mytag');
        $value = $this->redis()->hget($tagKey, 'mykey');
        $this->assertEquals(StoreContext::TAG_FIELD_VALUE, $value);
    }

    public function testAnyModeRegistryContainsTagWithExpiryScore(): void
    {
        $this->setTagMode(TagMode::Any);

        $beforeTime = time();
        Cache::tags(['registrytest'])->put('key1', 'value', 3600);
        $afterTime = time();

        // Check the registry contains the tag
        $registry = $this->getAnyModeRegistry();
        $this->assertArrayHasKey('registrytest', $registry);

        $score = (int) $registry['registrytest'];

        // Score should be approximately now + 3600 seconds
        $expectedMin = $beforeTime + 3600;
        $expectedMax = $afterTime + 3600 + 1;

        $this->assertGreaterThanOrEqual($expectedMin, $score);
        $this->assertLessThanOrEqual($expectedMax, $score);
    }

    // =========================================================================
    // FLUSH BEHAVIOR - KEYS SHOULD BE CLEANED UP
    // =========================================================================

    public function testAllModeFlushRemovesTagZset(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['flushtest'])->put('item1', 'value1', 60);
        Cache::tags(['flushtest'])->put('item2', 'value2', 60);

        $this->assertRedisKeyExists($this->allModeTagKey('flushtest'));

        Cache::tags(['flushtest'])->flush();

        // Tag ZSET should be deleted after flush
        $this->assertRedisKeyNotExists($this->allModeTagKey('flushtest'));
    }

    public function testAnyModeFlushRemovesAllStructures(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['flushtest'])->put('item1', 'value1', 60);
        Cache::tags(['flushtest'])->put('item2', 'value2', 60);

        // Verify structures exist
        $this->assertRedisKeyExists($this->anyModeTagKey('flushtest'));
        $this->assertRedisKeyExists($this->anyModeReverseIndexKey('item1'));
        $this->assertRedisKeyExists($this->anyModeReverseIndexKey('item2'));

        Cache::tags(['flushtest'])->flush();

        // All structures should be deleted
        $this->assertRedisKeyNotExists($this->anyModeTagKey('flushtest'));
        $this->assertRedisKeyNotExists($this->anyModeReverseIndexKey('item1'));
        $this->assertRedisKeyNotExists($this->anyModeReverseIndexKey('item2'));

        // Cache values should be gone
        $this->assertNull(Cache::get('item1'));
        $this->assertNull(Cache::get('item2'));
    }

    // =========================================================================
    // COLLISION PREVENTION TESTS
    // =========================================================================

    public function testAllModeNoCollisionWhenTagIsNamedEntries(): void
    {
        $this->setTagMode(TagMode::All);

        // A tag named 'entries' should not collide with internal structures
        Cache::tags(['entries'])->put('item', 'value', 60);

        // Tag ZSET: {prefix}_all:tag:entries:entries
        $tagKey = $this->allModeTagKey('entries');
        $this->assertRedisKeyExists($tagKey);
        $this->assertKeyEndsWithSuffix(':entries:entries', $tagKey);

        // Verify item works
        $this->assertSame('value', Cache::tags(['entries'])->get('item'));

        Cache::tags(['entries'])->flush();
        $this->assertNull(Cache::tags(['entries'])->get('item'));
    }

    public function testAnyModeNoCollisionWhenTagIsNamedRegistry(): void
    {
        $this->setTagMode(TagMode::Any);

        // 'registry' is the name of our internal ZSET, but tag hashes have :entries suffix
        Cache::tags(['registry'])->put('item', 'value', 60);

        // Tag hash for 'registry' tag: {prefix}_any:tag:registry:entries (HASH)
        // Actual registry: {prefix}_any:tag:registry (ZSET)
        // These are different keys
        $tagHashKey = $this->anyModeTagKey('registry');
        $registryKey = $this->anyModeRegistryKey();

        $this->assertRedisKeyExists($tagHashKey);
        $this->assertRedisKeyExists($registryKey);
        $this->assertNotEquals($tagHashKey, $registryKey);

        // Verify they are different types
        $this->assertEquals(Redis::REDIS_HASH, $this->redis()->type($tagHashKey));
        $this->assertEquals(Redis::REDIS_ZSET, $this->redis()->type($registryKey));

        // Verify both work correctly
        $this->assertSame('value', Cache::get('item'));
        Cache::tags(['registry'])->flush();
        $this->assertNull(Cache::get('item'));
    }

    public function testAnyModeNoCollisionWhenTagContainsEntriesSuffix(): void
    {
        $this->setTagMode(TagMode::Any);

        // A tag named 'posts:entries' should not collide with the tag hash for 'posts'
        Cache::tags(['posts'])->put('item1', 'value1', 60);
        Cache::tags(['posts:entries'])->put('item2', 'value2', 60);

        // Tag hash for 'posts': {prefix}_any:tag:posts:entries
        // Tag hash for 'posts:entries': {prefix}_any:tag:posts:entries:entries
        $postsTagKey = $this->anyModeTagKey('posts');
        $postsEntriesTagKey = $this->anyModeTagKey('posts:entries');

        $this->assertRedisKeyExists($postsTagKey);
        $this->assertRedisKeyExists($postsEntriesTagKey);
        $this->assertNotEquals($postsTagKey, $postsEntriesTagKey);

        // Verify both items exist independently
        $this->assertSame('value1', Cache::get('item1'));
        $this->assertSame('value2', Cache::get('item2'));

        // Flushing 'posts' should not affect 'posts:entries'
        Cache::tags(['posts'])->flush();
        $this->assertNull(Cache::get('item1'));
        $this->assertSame('value2', Cache::get('item2'));
    }

    public function testAnyModeNoCollisionWhenTagLooksLikeInternalSegment(): void
    {
        $this->setTagMode(TagMode::Any);

        // Tags that look like internal segments should still work
        Cache::tags(['_any:tag:fake'])->put('item', 'value', 60);

        $this->assertSame('value', Cache::get('item'));

        // The tag hash key will be: {prefix}_any:tag:_any:tag:fake:entries
        // This is ugly but doesn't collide with anything
        $tagKey = $this->anyModeTagKey('_any:tag:fake');
        $this->assertRedisKeyExists($tagKey);

        Cache::tags(['_any:tag:fake'])->flush();
        $this->assertNull(Cache::get('item'));
    }

    public function testAllModeNoCollisionWhenTagLooksLikeInternalSegment(): void
    {
        $this->setTagMode(TagMode::All);

        // Tags that look like internal segments should still work
        Cache::tags(['_all:tag:fake'])->put('item', 'value', 60);

        $this->assertSame('value', Cache::tags(['_all:tag:fake'])->get('item'));

        // The tag ZSET key will be: {prefix}_all:tag:_all:tag:fake:entries
        $tagKey = $this->allModeTagKey('_all:tag:fake');
        $this->assertRedisKeyExists($tagKey);

        Cache::tags(['_all:tag:fake'])->flush();
        $this->assertNull(Cache::tags(['_all:tag:fake'])->get('item'));
    }

    // =========================================================================
    // SPECIAL CHARACTERS IN TAG NAMES
    // =========================================================================

    public function testAllModeHandlesSpecialCharactersInTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['user:123', 'role:admin'])->put('special', 'value', 60);

        $this->assertRedisKeyExists($this->allModeTagKey('user:123'));
        $this->assertRedisKeyExists($this->allModeTagKey('role:admin'));

        $this->assertSame('value', Cache::tags(['user:123', 'role:admin'])->get('special'));
    }

    public function testAnyModeHandlesSpecialCharactersInTags(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['user:123', 'role:admin'])->put('special', 'value', 60);

        $this->assertRedisKeyExists($this->anyModeTagKey('user:123'));
        $this->assertRedisKeyExists($this->anyModeTagKey('role:admin'));

        $this->assertSame('value', Cache::get('special'));
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function assertKeyContainsSegment(string $segment, string $key): void
    {
        $this->assertTrue(
            str_contains($key, $segment),
            "Failed asserting that key '{$key}' contains segment '{$segment}'"
        );
    }

    private function assertKeyEndsWithSuffix(string $suffix, string $key): void
    {
        $this->assertTrue(
            str_ends_with($key, $suffix),
            "Failed asserting that key '{$key}' ends with suffix '{$suffix}'"
        );
    }
}
