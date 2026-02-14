<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for tag consistency and integrity.
 *
 * Tests verify that tag tracking structures remain consistent:
 * - Tag replacement when overwriting with different tags (any mode only)
 * - Orphan creation on flush (lazy cleanup behavior)
 * - Reverse index cleanup (any mode only)
 * - Complete cleanup after full flush
 *
 * NOTE: Hypervel uses LAZY cleanup mode only. Orphaned entries are left
 * behind after flush and cleaned up by the prune command.
 *
 * @internal
 * @coversNothing
 */
class TagConsistencyIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // FULL FLUSH CLEANUP - BOTH MODES
    // =========================================================================

    public function testAllModeFullFlushCleansAllKeys(): void
    {
        $this->setTagMode(TagMode::All);

        // Seed data with various configurations
        Cache::put('simple-key', 'value', 60);
        Cache::tags(['tag1'])->put('tagged-key-1', 'value', 60);
        Cache::tags(['tag1', 'tag2'])->put('tagged-key-2', 'value', 60);
        Cache::tags(['tag3'])->forever('forever-key', 'value');

        // Verify data exists
        $this->assertTrue(Cache::has('simple-key'));
        $this->assertNotNull(Cache::tags(['tag1'])->get('tagged-key-1'));
        $this->assertRedisKeyExists($this->allModeTagKey('tag1'));

        // Full flush
        Cache::flush();

        // Verify all keys are gone
        $this->assertNull(Cache::get('simple-key'));
        $this->assertNull(Cache::tags(['tag1'])->get('tagged-key-1'));
        $this->assertNull(Cache::tags(['tag1', 'tag2'])->get('tagged-key-2'));
        $this->assertNull(Cache::tags(['tag3'])->get('forever-key'));
    }

    public function testAnyModeFullFlushCleansAllKeys(): void
    {
        $this->setTagMode(TagMode::Any);

        // Seed data with various configurations
        Cache::put('simple-key', 'value', 60);
        Cache::tags(['tag1'])->put('tagged-key-1', 'value', 60);
        Cache::tags(['tag1', 'tag2'])->put('tagged-key-2', 'value', 60);
        Cache::tags(['tag3'])->forever('forever-key', 'value');

        // Verify data exists
        $this->assertTrue(Cache::has('simple-key'));
        $this->assertSame('value', Cache::get('tagged-key-1'));
        $this->assertRedisKeyExists($this->anyModeTagKey('tag1'));

        // Full flush
        Cache::flush();

        // Verify all keys are gone
        $this->assertNull(Cache::get('simple-key'));
        $this->assertNull(Cache::get('tagged-key-1'));
        $this->assertNull(Cache::get('tagged-key-2'));
        $this->assertNull(Cache::get('forever-key'));
    }

    // =========================================================================
    // TAG REPLACEMENT ON OVERWRITE - ANY MODE ONLY
    // =========================================================================

    public function testAnyModeReverseIndexCleanupOnOverwrite(): void
    {
        $this->setTagMode(TagMode::Any);

        // Put key with Tag A
        Cache::tags(['tag-a'])->put('my-key', 'value', 60);

        // Verify it's in Tag A's hash
        $this->assertTrue($this->anyModeTagHasEntry('tag-a', 'my-key'));
        $this->assertContains('tag-a', $this->getAnyModeReverseIndex('my-key'));

        // Overwrite same key with Tag B (removing Tag A association)
        Cache::tags(['tag-b'])->put('my-key', 'new-value', 60);

        // Verify it's in Tag B's hash
        $this->assertTrue($this->anyModeTagHasEntry('tag-b', 'my-key'));

        // Verify it is GONE from Tag A's hash (reverse index cleanup)
        $this->assertFalse($this->anyModeTagHasEntry('tag-a', 'my-key'));

        // Verify reverse index updated
        $reverseIndex = $this->getAnyModeReverseIndex('my-key');
        $this->assertContains('tag-b', $reverseIndex);
        $this->assertNotContains('tag-a', $reverseIndex);
    }

    public function testAnyModeTagReplacementWithMultipleTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Put key with tags A and B
        Cache::tags(['tag-a', 'tag-b'])->put('my-key', 'value', 60);

        // Verify in both tags
        $this->assertTrue($this->anyModeTagHasEntry('tag-a', 'my-key'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-b', 'my-key'));

        // Overwrite with tags C and D
        Cache::tags(['tag-c', 'tag-d'])->put('my-key', 'new-value', 60);

        // Verify removed from A and B
        $this->assertFalse($this->anyModeTagHasEntry('tag-a', 'my-key'));
        $this->assertFalse($this->anyModeTagHasEntry('tag-b', 'my-key'));

        // Verify added to C and D
        $this->assertTrue($this->anyModeTagHasEntry('tag-c', 'my-key'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-d', 'my-key'));
    }

    public function testAllModeOverwriteCreatesOrphanedEntries(): void
    {
        $this->setTagMode(TagMode::All);

        // Put key with Tag A
        Cache::tags(['tag-a'])->put('my-key', 'value', 60);

        // Get the namespaced key used in all mode
        $namespacedKey = Cache::tags(['tag-a'])->taggedItemKey('my-key');

        // Verify entry exists in tag A
        $this->assertTrue($this->allModeTagHasEntry('tag-a', $namespacedKey));

        // Overwrite with Tag B (different namespace)
        Cache::tags(['tag-b'])->put('my-key', 'new-value', 60);

        $newNamespacedKey = Cache::tags(['tag-b'])->taggedItemKey('my-key');

        // In all mode, there's no reverse index cleanup
        // The OLD entry in tag-a remains as an orphan (pointing to non-existent namespaced key)
        $this->assertTrue(
            $this->allModeTagHasEntry('tag-a', $namespacedKey),
            'All mode should leave orphaned entries (cleaned by prune command)'
        );

        // New entry should exist in tag-b
        $this->assertTrue($this->allModeTagHasEntry('tag-b', $newNamespacedKey));
    }

    // =========================================================================
    // LAZY FLUSH BEHAVIOR - ORPHAN CREATION
    // =========================================================================

    public function testAnyModeFlushLeavesOrphansInSharedTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Put item with Tag A and Tag B
        Cache::tags(['tag-a', 'tag-b'])->put('shared-key', 'value', 60);

        // Verify existence in both
        $this->assertTrue($this->anyModeTagHasEntry('tag-a', 'shared-key'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-b', 'shared-key'));

        // Flush Tag A
        Cache::tags(['tag-a'])->flush();

        // Item is gone from cache
        $this->assertNull(Cache::get('shared-key'));

        // Entry is gone from Tag A (flushed tag)
        $this->assertFalse($this->anyModeTagHasEntry('tag-a', 'shared-key'));

        // Orphan remains in Tag B (cleaned up by prune command)
        $this->assertTrue(
            $this->anyModeTagHasEntry('tag-b', 'shared-key'),
            'Orphaned entry should remain in shared tag until prune'
        );
    }

    public function testAllModeFlushLeavesOrphansInSharedTags(): void
    {
        $this->setTagMode(TagMode::All);

        // Put item with Tag A and Tag B
        Cache::tags(['tag-a', 'tag-b'])->put('shared-key', 'value', 60);

        $namespacedKey = Cache::tags(['tag-a', 'tag-b'])->taggedItemKey('shared-key');

        // Verify existence in both
        $this->assertTrue($this->allModeTagHasEntry('tag-a', $namespacedKey));
        $this->assertTrue($this->allModeTagHasEntry('tag-b', $namespacedKey));

        // Flush Tag A
        Cache::tags(['tag-a'])->flush();

        // Item is gone from cache
        $this->assertNull(Cache::tags(['tag-a', 'tag-b'])->get('shared-key'));

        // Entry should be removed from tag-a's ZSET
        $this->assertFalse($this->allModeTagHasEntry('tag-a', $namespacedKey));

        // Orphan remains in Tag B (cleaned up by prune command)
        $this->assertTrue(
            $this->allModeTagHasEntry('tag-b', $namespacedKey),
            'Orphaned entry should remain in shared tag until prune'
        );
    }

    // =========================================================================
    // FORGET CLEANUP - ANY MODE WITH REVERSE INDEX
    // =========================================================================

    public function testAnyModeForgetLeavesOrphanedTagEntries(): void
    {
        $this->setTagMode(TagMode::Any);

        // Put item with multiple tags
        Cache::tags(['tag-x', 'tag-y', 'tag-z'])->put('forget-me', 'value', 60);

        // Verify existence
        $this->assertTrue($this->anyModeTagHasEntry('tag-x', 'forget-me'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-y', 'forget-me'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-z', 'forget-me'));

        // Forget the item by key (non-tagged forget does NOT use reverse index)
        Cache::forget('forget-me');

        // Verify item is gone from cache
        $this->assertNull(Cache::get('forget-me'));

        // Orphaned entries remain in tag hashes (cleaned up by prune command)
        $this->assertTrue(
            $this->anyModeTagHasEntry('tag-x', 'forget-me'),
            'Orphaned entry should remain in tag hash until prune'
        );
        $this->assertTrue($this->anyModeTagHasEntry('tag-y', 'forget-me'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-z', 'forget-me'));

        // Reverse index also remains (orphaned)
        $this->assertNotEmpty($this->getAnyModeReverseIndex('forget-me'));
    }

    public function testAllModeForgetLeavesOrphanedTagEntries(): void
    {
        $this->setTagMode(TagMode::All);

        // Put item with multiple tags
        Cache::tags(['tag-x', 'tag-y'])->put('forget-me', 'value', 60);

        $namespacedKey = Cache::tags(['tag-x', 'tag-y'])->taggedItemKey('forget-me');

        // Verify existence
        $this->assertTrue($this->allModeTagHasEntry('tag-x', $namespacedKey));
        $this->assertTrue($this->allModeTagHasEntry('tag-y', $namespacedKey));

        // Forget via non-tagged facade (no reverse index in all mode)
        // This won't clean up tag entries because all mode uses namespaced keys
        Cache::forget('forget-me');

        // The cache key 'forget-me' without namespace should be deleted
        // But the namespaced key used by tags is different
        // So the tagged item still exists!
        $this->assertNotNull(
            Cache::tags(['tag-x', 'tag-y'])->get('forget-me'),
            'All mode uses namespaced keys, so Cache::forget("key") does not affect tagged items'
        );

        // To actually forget in all mode, use tags:
        Cache::tags(['tag-x', 'tag-y'])->forget('forget-me');
        $this->assertNull(Cache::tags(['tag-x', 'tag-y'])->get('forget-me'));

        // But orphans remain in tag ZSETs (lazy cleanup)
        $this->assertTrue($this->allModeTagHasEntry('tag-x', $namespacedKey));
        $this->assertTrue($this->allModeTagHasEntry('tag-y', $namespacedKey));
    }

    // =========================================================================
    // INCREMENT/DECREMENT TAG REPLACEMENT - ANY MODE ONLY
    // =========================================================================

    public function testAnyModeIncrementReplacesTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create item with initial tags
        Cache::tags(['tag1', 'tag2'])->put('counter', 10, 60);

        // Verify initial state
        $this->assertTrue($this->anyModeTagHasEntry('tag1', 'counter'));
        $this->assertTrue($this->anyModeTagHasEntry('tag2', 'counter'));

        // Increment with NEW tags (should replace old ones)
        Cache::tags(['tag3'])->increment('counter', 5);

        // Verify value
        $this->assertEquals(15, Cache::get('counter'));

        // Verify tags replaced
        $this->assertFalse($this->anyModeTagHasEntry('tag1', 'counter'));
        $this->assertFalse($this->anyModeTagHasEntry('tag2', 'counter'));
        $this->assertTrue($this->anyModeTagHasEntry('tag3', 'counter'));
    }

    public function testAnyModeDecrementReplacesTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create item with initial tags
        Cache::tags(['tag1', 'tag2'])->put('counter', 20, 60);

        // Decrement with NEW tags
        Cache::tags(['tag3', 'tag4'])->decrement('counter', 5);

        // Verify value
        $this->assertEquals(15, Cache::get('counter'));

        // Verify tags replaced
        $this->assertFalse($this->anyModeTagHasEntry('tag1', 'counter'));
        $this->assertFalse($this->anyModeTagHasEntry('tag2', 'counter'));
        $this->assertTrue($this->anyModeTagHasEntry('tag3', 'counter'));
        $this->assertTrue($this->anyModeTagHasEntry('tag4', 'counter'));
    }

    public function testAnyModeIncrementThenDecrementReplacesTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Initial state
        Cache::tags(['initial'])->put('counter', 10, 60);
        $this->assertTrue($this->anyModeTagHasEntry('initial', 'counter'));

        // Increment with tag A
        Cache::tags(['tag-a'])->increment('counter', 5);
        $this->assertEquals(15, Cache::get('counter'));
        $this->assertFalse($this->anyModeTagHasEntry('initial', 'counter'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-a', 'counter'));

        // Decrement with tag B
        Cache::tags(['tag-b'])->decrement('counter', 3);
        $this->assertEquals(12, Cache::get('counter'));
        $this->assertFalse($this->anyModeTagHasEntry('tag-a', 'counter'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-b', 'counter'));
    }

    // =========================================================================
    // REGISTRY CONSISTENCY - ANY MODE ONLY
    // =========================================================================

    public function testAnyModeRegistryTracksActiveTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create items with different tags
        Cache::tags(['users'])->put('user:1', 'Alice', 60);
        Cache::tags(['posts'])->put('post:1', 'Hello', 60);
        Cache::tags(['comments'])->put('comment:1', 'Nice!', 60);

        // Verify registry has all tags
        $this->assertTrue($this->anyModeRegistryHasTag('users'));
        $this->assertTrue($this->anyModeRegistryHasTag('posts'));
        $this->assertTrue($this->anyModeRegistryHasTag('comments'));
    }

    public function testAnyModeRegistryScoresUpdateWithTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create item with short TTL
        Cache::tags(['short-ttl'])->put('item1', 'value', 10);

        $registry1 = $this->getAnyModeRegistry();
        $score1 = $registry1['short-ttl'] ?? 0;

        // Create item with longer TTL
        Cache::tags(['short-ttl'])->put('item2', 'value', 300);

        $registry2 = $this->getAnyModeRegistry();
        $score2 = $registry2['short-ttl'] ?? 0;

        // Score should have increased (GT flag in ZADD)
        $this->assertGreaterThan($score1, $score2);
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testAnyModeOverwriteWithSameTagsDoesNotCreateOrphans(): void
    {
        $this->setTagMode(TagMode::Any);

        // Put item with tags
        Cache::tags(['tag-a', 'tag-b'])->put('my-key', 'value1', 60);

        // Overwrite with SAME tags
        Cache::tags(['tag-a', 'tag-b'])->put('my-key', 'value2', 60);

        // Verify entries exist once (not duplicated)
        $this->assertTrue($this->anyModeTagHasEntry('tag-a', 'my-key'));
        $this->assertTrue($this->anyModeTagHasEntry('tag-b', 'my-key'));

        // Value should be updated
        $this->assertSame('value2', Cache::get('my-key'));
    }

    public function testAnyModeOverwriteWithPartialTagOverlap(): void
    {
        $this->setTagMode(TagMode::Any);

        // Put item with tags A and B
        Cache::tags(['tag-a', 'tag-b'])->put('my-key', 'value1', 60);

        // Overwrite with tags B and C (partial overlap)
        Cache::tags(['tag-b', 'tag-c'])->put('my-key', 'value2', 60);

        // A should be removed
        $this->assertFalse($this->anyModeTagHasEntry('tag-a', 'my-key'));

        // B should still exist (was in both)
        $this->assertTrue($this->anyModeTagHasEntry('tag-b', 'my-key'));

        // C should be added
        $this->assertTrue($this->anyModeTagHasEntry('tag-c', 'my-key'));

        // Reverse index should have only B and C
        $reverseIndex = $this->getAnyModeReverseIndex('my-key');
        sort($reverseIndex);
        $this->assertEquals(['tag-b', 'tag-c'], $reverseIndex);
    }
}
