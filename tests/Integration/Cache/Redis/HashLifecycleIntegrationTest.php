<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for Redis hash lifecycle behavior (ANY MODE ONLY).
 *
 * Tests critical Redis behavior that our package relies on:
 * - Redis automatically deletes empty hashes when all fields expire
 * - Hash structures have no TTL set on them (only fields have TTL via HSETEX)
 * - This reduces the need for aggressive cleanup of expired tag hashes
 *
 * NOTE: These tests require Redis 8.0+ with HSETEX support.
 *
 * @internal
 * @coversNothing
 */
class HashLifecycleIntegrationTest extends RedisCacheIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setTagMode(TagMode::Any);
    }

    // =========================================================================
    // AUTOMATIC HASH DELETION WHEN ALL FIELDS EXPIRE
    // =========================================================================

    public function testAutoDeletesHashWhenAllFieldsExpireNaturally(): void
    {
        // Create items with short TTL
        Cache::tags(['lifecycle-test'])->put('lifecycle:item1', 'value1', 1);
        Cache::tags(['lifecycle-test'])->put('lifecycle:item2', 'value2', 1);

        $tagHash = $this->anyModeTagKey('lifecycle-test');

        // Verify hash exists with fields
        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(2, $this->redis()->hlen($tagHash));

        // Hash structure itself has no TTL (only fields have TTL)
        $this->assertEquals(-1, $this->redis()->ttl($tagHash));

        // Wait for fields to expire
        usleep(1500000); // 1.5 seconds

        // Redis should have automatically deleted the entire hash
        $this->assertRedisKeyNotExists($tagHash);
    }

    public function testAutoDeletesHashWhenLastRemainingFieldExpires(): void
    {
        // Create items with different TTLs
        Cache::tags(['staggered-test'])->put('lifecycle:short', 'value1', 1);  // 1 second
        Cache::tags(['staggered-test'])->put('lifecycle:long', 'value2', 2);   // 2 seconds

        $tagHash = $this->anyModeTagKey('staggered-test');

        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(2, $this->redis()->hlen($tagHash));

        // After 1.5 seconds, short field should expire but long field remains
        usleep(1500000); // 1.5 seconds

        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(1, $this->redis()->hlen($tagHash));

        // After 1 more second, last field expires
        sleep(1);

        // Redis should automatically delete the empty hash
        $this->assertRedisKeyNotExists($tagHash);
    }

    public function testKeepsHashAliveWhileAnyFieldRemainsUnexpired(): void
    {
        // Create one item with TTL and one forever
        Cache::tags(['mixed-ttl-test'])->put('lifecycle:short', 'value1', 1);
        Cache::tags(['mixed-ttl-test'])->forever('lifecycle:forever', 'value2');

        $tagHash = $this->anyModeTagKey('mixed-ttl-test');

        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(2, $this->redis()->hlen($tagHash));

        // After 1.5 seconds, short field expires
        usleep(1500000); // 1.5 seconds

        // Hash still exists because forever field remains
        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(1, $this->redis()->hlen($tagHash));

        // Forever field should still be there
        $this->assertTrue($this->anyModeTagHasEntry('mixed-ttl-test', 'lifecycle:forever'));
    }

    // =========================================================================
    // ORPHANED FIELDS BEHAVIOR (LAZY CLEANUP MODE)
    // =========================================================================

    public function testCreatesOrphanedFieldsWhenCacheKeyDeletedButFieldRemains(): void
    {
        // Create forever item (no field expiration)
        Cache::tags(['orphan-test'])->forever('lifecycle:orphan', 'value');

        $tagHash = $this->anyModeTagKey('orphan-test');

        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(1, $this->redis()->hlen($tagHash));

        // Manually delete the cache key (simulates flush of another tag)
        Cache::forget('lifecycle:orphan');

        // Hash field still exists even though cache key is gone
        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(1, $this->redis()->hlen($tagHash));

        // The field is now "orphaned" - points to non-existent cache key
        $prefix = $this->getCachePrefix();
        $this->assertFalse($this->redis()->exists($prefix . 'lifecycle:orphan') > 0);

        // This is what prune command is designed to clean up
    }

    public function testOrphanedFieldsFromLazyModeFlushExpireNaturallyIfTheyHaveTtl(): void
    {
        // Create item with TTL
        Cache::tags(['natural-cleanup'])->put('lifecycle:temp', 'value', 1);

        $tagHash = $this->anyModeTagKey('natural-cleanup');

        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(1, $this->redis()->hlen($tagHash));

        // Simulate flush by deleting cache key but leaving field
        Cache::forget('lifecycle:temp');

        // Orphaned field still exists
        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(1, $this->redis()->hlen($tagHash));

        // Wait for original TTL to expire
        usleep(1500000); // 1.5 seconds

        // Hash should be auto-deleted when orphaned field expired naturally
        $this->assertRedisKeyNotExists($tagHash);
    }

    // =========================================================================
    // HASH STRUCTURE CHARACTERISTICS
    // =========================================================================

    public function testHashHasNoTtlOnlyFieldsHaveTtl(): void
    {
        Cache::tags(['no-hash-ttl'])->put('item1', 'value1', 60);
        Cache::tags(['no-hash-ttl'])->put('item2', 'value2', 30);

        $tagHash = $this->anyModeTagKey('no-hash-ttl');

        // Hash structure itself should have no TTL (indefinite)
        $hashTtl = $this->redis()->ttl($tagHash);
        $this->assertEquals(-1, $hashTtl);

        // But individual fields should have TTL
        $ttlResult1 = $this->redis()->httl($tagHash, ['item1']);
        $ttl1 = $ttlResult1[0] ?? $ttlResult1;
        $this->assertGreaterThan(0, $ttl1);

        $ttlResult2 = $this->redis()->httl($tagHash, ['item2']);
        $ttl2 = $ttlResult2[0] ?? $ttlResult2;
        $this->assertGreaterThan(0, $ttl2);
    }

    public function testMultipleTagsAllFieldsExpire(): void
    {
        // Create item with multiple tags, all with short TTL
        Cache::tags(['multi-expire-1', 'multi-expire-2'])->put('multi-item', 'value', 1);

        $tagHash1 = $this->anyModeTagKey('multi-expire-1');
        $tagHash2 = $this->anyModeTagKey('multi-expire-2');

        $this->assertRedisKeyExists($tagHash1);
        $this->assertRedisKeyExists($tagHash2);

        // Wait for fields to expire
        usleep(1500000); // 1.5 seconds

        // Both hashes should be auto-deleted
        $this->assertRedisKeyNotExists($tagHash1);
        $this->assertRedisKeyNotExists($tagHash2);
    }

    public function testForeverFieldsPreventHashDeletion(): void
    {
        // Create only forever items
        Cache::tags(['forever-only'])->forever('item1', 'value1');
        Cache::tags(['forever-only'])->forever('item2', 'value2');

        $tagHash = $this->anyModeTagKey('forever-only');

        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(2, $this->redis()->hlen($tagHash));

        // Wait some time
        sleep(1);

        // Hash should still exist with both fields
        $this->assertRedisKeyExists($tagHash);
        $this->assertEquals(2, $this->redis()->hlen($tagHash));
    }
}
