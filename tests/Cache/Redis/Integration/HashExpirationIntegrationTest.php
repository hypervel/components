<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for hash field expiration (ANY MODE ONLY).
 *
 * Tests HSETEX hash field expiration behavior:
 * - Field TTL matches cache TTL
 * - Fields expire automatically with cache keys
 * - Different TTLs for items with same tag
 * - Forever items have no field expiration
 * - Updating item updates field expiration
 *
 * NOTE: These tests require Redis 8.0+ with HSETEX/HTTL support.
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class HashExpirationIntegrationTest extends CacheRedisIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setTagMode(TagMode::Any);
    }

    // =========================================================================
    // HASH FIELD TTL VERIFICATION
    // =========================================================================

    public function testHashFieldExpirationMatchesCacheTtl(): void
    {
        Cache::tags(['expiring'])->put('short-lived', 'value', 10);

        // Check that tag hash exists and has the field
        $this->assertTrue($this->anyModeTagHasEntry('expiring', 'short-lived'));

        // Check that TTL is set on the hash field using HTTL
        $tagKey = $this->anyModeTagKey('expiring');
        $ttlResult = $this->redis()->httl($tagKey, ['short-lived']);
        $ttl = $ttlResult[0] ?? $ttlResult;

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(10, $ttl);
    }

    public function testHashFieldsExpireAutomaticallyWithCacheKeys(): void
    {
        // Store with 1 second TTL
        Cache::tags(['quick'])->put('flash', 'value', 1);

        // Should exist initially
        $this->assertTrue($this->anyModeTagHasEntry('quick', 'flash'));
        $this->assertSame('value', Cache::get('flash'));

        // Wait for expiration
        sleep(2);

        // Cache key should be gone
        $this->assertNull(Cache::get('flash'));

        // Hash field should also be gone (handled by Redis HSETEX auto-expiration)
        $this->assertFalse($this->anyModeTagHasEntry('quick', 'flash'));
    }

    public function testDifferentTtlsForItemsWithSameTag(): void
    {
        Cache::tags(['mixed-ttl'])->put('short', 'value1', 1);
        Cache::tags(['mixed-ttl'])->put('long', 'value2', 60);

        // Both should exist initially
        $this->assertTrue($this->anyModeTagHasEntry('mixed-ttl', 'short'));
        $this->assertTrue($this->anyModeTagHasEntry('mixed-ttl', 'long'));

        // Wait for short to expire
        sleep(2);

        // Short should be gone (both cache key and hash field)
        $this->assertNull(Cache::get('short'));
        $this->assertFalse($this->anyModeTagHasEntry('mixed-ttl', 'short'));

        // Long should remain
        $this->assertTrue($this->anyModeTagHasEntry('mixed-ttl', 'long'));
        $this->assertSame('value2', Cache::get('long'));
    }

    public function testForeverItemsDoNotSetHashFieldExpiration(): void
    {
        Cache::tags(['permanent'])->forever('eternal', 'forever value');

        // Field should exist
        $this->assertTrue($this->anyModeTagHasEntry('permanent', 'eternal'));

        // TTL should be -1 (no expiration)
        $tagKey = $this->anyModeTagKey('permanent');
        $ttlResult = $this->redis()->httl($tagKey, ['eternal']);
        $ttl = $ttlResult[0] ?? $ttlResult;

        $this->assertEquals(-1, $ttl);
    }

    public function testUpdatingItemUpdatesHashFieldExpiration(): void
    {
        // Store with short TTL
        Cache::tags(['updating'])->put('item', 'value1', 5);
        $tagKey = $this->anyModeTagKey('updating');
        $ttlResult1 = $this->redis()->httl($tagKey, ['item']);
        $ttl1 = $ttlResult1[0] ?? $ttlResult1;

        // Update with longer TTL
        Cache::tags(['updating'])->put('item', 'value2', 60);
        $ttlResult2 = $this->redis()->httl($tagKey, ['item']);
        $ttl2 = $ttlResult2[0] ?? $ttlResult2;

        // New TTL should be longer
        $this->assertGreaterThan($ttl1, $ttl2);
        $this->assertGreaterThan(50, $ttl2);
    }

    // =========================================================================
    // EXPIRATION WITH MULTIPLE TAGS
    // =========================================================================

    public function testExpirationSetOnAllTagHashes(): void
    {
        Cache::tags(['tag1', 'tag2', 'tag3'])->put('multi-tag-item', 'value', 30);

        // All tag hashes should have the field with TTL
        foreach (['tag1', 'tag2', 'tag3'] as $tag) {
            $this->assertTrue($this->anyModeTagHasEntry($tag, 'multi-tag-item'));

            $tagKey = $this->anyModeTagKey($tag);
            $ttlResult = $this->redis()->httl($tagKey, ['multi-tag-item']);
            $ttl = $ttlResult[0] ?? $ttlResult;

            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(30, $ttl);
        }
    }

    public function testFieldsExpireAcrossAllTagHashes(): void
    {
        Cache::tags(['exp1', 'exp2'])->put('expiring-multi', 'value', 1);

        // Both tag hashes should have the field initially
        $this->assertTrue($this->anyModeTagHasEntry('exp1', 'expiring-multi'));
        $this->assertTrue($this->anyModeTagHasEntry('exp2', 'expiring-multi'));

        // Wait for expiration
        sleep(2);

        // Fields should be gone from both tag hashes
        $this->assertFalse($this->anyModeTagHasEntry('exp1', 'expiring-multi'));
        $this->assertFalse($this->anyModeTagHasEntry('exp2', 'expiring-multi'));
    }

    // =========================================================================
    // EXPIRATION AND CACHE OPERATIONS
    // =========================================================================

    public function testIncrementMaintainsTagTracking(): void
    {
        Cache::tags(['counters'])->put('views', 10, 60);
        $this->assertTrue($this->anyModeTagHasEntry('counters', 'views'));

        Cache::tags(['counters'])->increment('views');
        $this->assertEquals(11, Cache::get('views'));

        // Field should still exist in tag hash
        $this->assertTrue($this->anyModeTagHasEntry('counters', 'views'));
    }

    public function testDecrementMaintainsTagTracking(): void
    {
        Cache::tags(['counters'])->put('balance', 100, 60);
        $this->assertTrue($this->anyModeTagHasEntry('counters', 'balance'));

        Cache::tags(['counters'])->decrement('balance', 25);
        $this->assertEquals(75, Cache::get('balance'));

        // Field should still exist in tag hash
        $this->assertTrue($this->anyModeTagHasEntry('counters', 'balance'));
    }

    public function testAddWithExpirationSetsHashFieldTtl(): void
    {
        $result = Cache::tags(['add_test'])->add('new_item', 'value', 30);
        $this->assertTrue($result);

        $this->assertTrue($this->anyModeTagHasEntry('add_test', 'new_item'));

        $tagKey = $this->anyModeTagKey('add_test');
        $ttlResult = $this->redis()->httl($tagKey, ['new_item']);
        $ttl = $ttlResult[0] ?? $ttlResult;

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30, $ttl);
    }
}
