<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for prune (cleanup) operations.
 *
 * Tests orphan handling for both tag modes:
 * - Flush leaves orphaned entries in other tags (lazy cleanup behavior)
 * - Prune command removes orphaned entries
 * - Prune preserves valid entries
 * - Prune deletes empty tag structures
 *
 * @group integration
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class PruneIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // ANY MODE - ORPHAN CREATION
    // =========================================================================

    public function testAnyModeFlushLeavesOrphanedFieldsInOtherTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Store items with multiple tags
        Cache::tags(['posts', 'user:1'])->put('post:1', 'data', 60);
        Cache::tags(['posts', 'user:2'])->put('post:2', 'data', 60);
        Cache::tags(['posts', 'featured'])->put('post:3', 'data', 60);

        // Flush one tag
        Cache::tags(['posts'])->flush();

        // All cache keys should be gone
        $this->assertNull(Cache::get('post:1'));
        $this->assertNull(Cache::get('post:2'));
        $this->assertNull(Cache::get('post:3'));

        // But other tag hashes should still have orphaned fields
        $this->assertTrue(
            $this->anyModeTagHasEntry('user:1', 'post:1'),
            'user:1 hash should have orphaned field for post:1'
        );
        $this->assertTrue(
            $this->anyModeTagHasEntry('user:2', 'post:2'),
            'user:2 hash should have orphaned field for post:2'
        );
        $this->assertTrue(
            $this->anyModeTagHasEntry('featured', 'post:3'),
            'featured hash should have orphaned field for post:3'
        );
    }

    public function testAnyModeForgetLeavesOrphanedFields(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'user:1'])->put('post:1', 'data', 60);

        // Forget the item directly
        Cache::forget('post:1');

        // Cache key should be gone
        $this->assertNull(Cache::get('post:1'));

        // But tag hash fields remain (orphaned)
        $this->assertTrue(
            $this->anyModeTagHasEntry('posts', 'post:1'),
            'posts hash should have orphaned field'
        );
        $this->assertTrue(
            $this->anyModeTagHasEntry('user:1', 'post:1'),
            'user:1 hash should have orphaned field'
        );
    }

    // =========================================================================
    // ANY MODE - PRUNE COMMAND
    // =========================================================================

    public function testAnyModePruneRemovesOrphanedFields(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create orphaned fields
        Cache::tags(['posts', 'user:1'])->put('post:1', 'data', 60);
        Cache::tags(['posts', 'user:2'])->put('post:2', 'data', 60);
        Cache::tags(['posts'])->flush(); // Leaves orphans in user:1 and user:2

        // Verify orphans exist
        $this->assertTrue($this->anyModeTagHasEntry('user:1', 'post:1'));
        $this->assertTrue($this->anyModeTagHasEntry('user:2', 'post:2'));

        // Run prune operation
        $this->store()->anyTagOps()->prune()->execute();

        // Orphans should be removed
        $this->assertFalse(
            $this->anyModeTagHasEntry('user:1', 'post:1'),
            'Orphaned field post:1 should be removed from user:1'
        );
        $this->assertFalse(
            $this->anyModeTagHasEntry('user:2', 'post:2'),
            'Orphaned field post:2 should be removed from user:2'
        );
    }

    public function testAnyModePruneDeletesEmptyTagHashes(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create item with single tag
        Cache::tags(['user:1'])->put('post:1', 'data', 60);

        // Verify hash exists
        $this->assertRedisKeyExists($this->anyModeTagKey('user:1'));

        // Forget item (leaves orphaned field)
        Cache::forget('post:1');

        // Orphan exists
        $this->assertTrue($this->anyModeTagHasEntry('user:1', 'post:1'));

        // Run prune
        $this->store()->anyTagOps()->prune()->execute();

        // Hash should be deleted (was empty after orphan removal)
        $this->assertRedisKeyNotExists($this->anyModeTagKey('user:1'));
    }

    public function testAnyModePrunePreservesValidFields(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create items
        Cache::tags(['posts', 'user:1'])->put('post:1', 'data1', 60);
        Cache::tags(['posts', 'user:2'])->put('post:2', 'data2', 60);

        // Flush just user:1 (deletes post:1 cache key)
        Cache::tags(['user:1'])->flush();

        // posts hash should have orphaned post:1 and valid post:2
        $this->assertTrue($this->anyModeTagHasEntry('posts', 'post:1')); // Orphaned
        $this->assertTrue($this->anyModeTagHasEntry('posts', 'post:2')); // Valid

        // Run prune
        $this->store()->anyTagOps()->prune()->execute();

        // Orphan removed, valid field preserved
        $this->assertFalse(
            $this->anyModeTagHasEntry('posts', 'post:1'),
            'Orphaned field post:1 should be removed'
        );
        $this->assertTrue(
            $this->anyModeTagHasEntry('posts', 'post:2'),
            'Valid field post:2 should be preserved'
        );

        // post:2 data should still be accessible
        $this->assertSame('data2', Cache::get('post:2'));
    }

    public function testAnyModePruneHandlesMultipleTagHashes(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create items in multiple tags
        for ($i = 1; $i <= 5; ++$i) {
            Cache::tags(["tag{$i}", 'common'])->put("key{$i}", "data{$i}", 60);
        }

        // Flush common tag
        Cache::tags(['common'])->flush();

        // Verify orphans in all tag hashes
        for ($i = 1; $i <= 5; ++$i) {
            $this->assertTrue(
                $this->anyModeTagHasEntry("tag{$i}", "key{$i}"),
                "tag{$i} should have orphaned field"
            );
        }

        // Run prune
        $this->store()->anyTagOps()->prune()->execute();

        // All orphans should be removed
        for ($i = 1; $i <= 5; ++$i) {
            $this->assertFalse(
                $this->anyModeTagHasEntry("tag{$i}", "key{$i}"),
                "Orphan in tag{$i} should be removed"
            );
        }
    }

    public function testAnyModePruneHandlesLargeNumberOfOrphans(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create many items
        for ($i = 1; $i <= 50; ++$i) {
            Cache::tags(['posts', "user:{$i}"])->put("post:{$i}", "data{$i}", 60);
        }

        // Flush posts tag
        Cache::tags(['posts'])->flush();

        // Verify some orphans exist
        $this->assertTrue($this->anyModeTagHasEntry('user:1', 'post:1'));
        $this->assertTrue($this->anyModeTagHasEntry('user:25', 'post:25'));
        $this->assertTrue($this->anyModeTagHasEntry('user:50', 'post:50'));

        // Run prune
        $this->store()->anyTagOps()->prune()->execute();

        // All orphans should be removed
        for ($i = 1; $i <= 50; ++$i) {
            $this->assertFalse(
                $this->anyModeTagHasEntry("user:{$i}", "post:{$i}"),
                "Orphan in user:{$i} should be removed"
            );
        }
    }

    public function testAnyModePruneHandlesForeverItems(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts', 'user:1'])->forever('post:1', 'data');

        // Flush posts tag
        Cache::tags(['posts'])->flush();

        // Cache key should be gone
        $this->assertNull(Cache::get('post:1'));

        // Orphaned field in user:1
        $this->assertTrue($this->anyModeTagHasEntry('user:1', 'post:1'));

        // Prune should remove it
        $this->store()->anyTagOps()->prune()->execute();

        $this->assertFalse($this->anyModeTagHasEntry('user:1', 'post:1'));
    }

    // =========================================================================
    // ALL MODE - ORPHAN CREATION
    // =========================================================================

    public function testAllModeFlushLeavesOrphanedEntriesInOtherTags(): void
    {
        $this->setTagMode(TagMode::All);

        // Store items with multiple tags
        Cache::tags(['posts', 'user:1'])->put('post:1', 'data', 60);
        Cache::tags(['posts', 'user:2'])->put('post:2', 'data', 60);

        // Flush posts tag
        Cache::tags(['posts'])->flush();

        // Cache keys should be gone (posts ZSET deleted)
        $this->assertNull(Cache::tags(['posts', 'user:1'])->get('post:1'));
        $this->assertNull(Cache::tags(['posts', 'user:2'])->get('post:2'));

        // But other tag ZSETs should still have orphaned entries
        // (The namespaced key entries in user:1 and user:2 ZSETs are orphaned)
        $this->assertNotEmpty(
            $this->getAllModeTagEntries('user:1'),
            'user:1 ZSET should have orphaned entry'
        );
        $this->assertNotEmpty(
            $this->getAllModeTagEntries('user:2'),
            'user:2 ZSET should have orphaned entry'
        );
    }

    // =========================================================================
    // ALL MODE - PRUNE COMMAND
    // =========================================================================

    public function testAllModePruneRemovesOrphanedEntries(): void
    {
        $this->setTagMode(TagMode::All);

        // Create orphaned entries
        Cache::tags(['posts', 'user:1'])->put('post:1', 'data', 60);
        Cache::tags(['posts', 'user:2'])->put('post:2', 'data', 60);
        Cache::tags(['posts'])->flush(); // Leaves orphans in user:1 and user:2

        // Verify orphans exist
        $this->assertNotEmpty($this->getAllModeTagEntries('user:1'));
        $this->assertNotEmpty($this->getAllModeTagEntries('user:2'));

        // Run prune operation (scans all tags)
        $this->store()->allTagOps()->prune()->execute();

        // Orphans should be removed (ZSETs deleted or emptied)
        $this->assertEmpty(
            $this->getAllModeTagEntries('user:1'),
            'Orphaned entries should be removed from user:1'
        );
        $this->assertEmpty(
            $this->getAllModeTagEntries('user:2'),
            'Orphaned entries should be removed from user:2'
        );
    }

    public function testAllModePrunePreservesValidEntries(): void
    {
        $this->setTagMode(TagMode::All);

        // Create items
        Cache::tags(['posts'])->put('post:1', 'data1', 60);
        Cache::tags(['posts'])->put('post:2', 'data2', 60);

        // Forget just post:1 (direct forget doesn't clean tag entries in all mode)
        Cache::tags(['posts'])->forget('post:1');

        // Verify post:1 is gone but post:2 exists
        $this->assertNull(Cache::tags(['posts'])->get('post:1'));
        $this->assertSame('data2', Cache::tags(['posts'])->get('post:2'));

        // ZSET should still have entries
        $entriesBefore = $this->getAllModeTagEntries('posts');
        $this->assertCount(2, $entriesBefore); // Both entries still in ZSET

        // Run prune (will clean stale entries based on TTL)
        $this->store()->allTagOps()->prune()->execute();

        // post:2 should still be accessible
        $this->assertSame('data2', Cache::tags(['posts'])->get('post:2'));
    }

    public function testAllModePruneHandlesMultipleTags(): void
    {
        $this->setTagMode(TagMode::All);

        // Create items in multiple tags
        for ($i = 1; $i <= 5; ++$i) {
            Cache::tags(["tag{$i}"])->put("key{$i}", "data{$i}", 60);
        }

        // Verify all ZSETs exist
        for ($i = 1; $i <= 5; ++$i) {
            $this->assertNotEmpty($this->getAllModeTagEntries("tag{$i}"));
        }

        // Flush all tags individually to create state where cache keys are gone
        for ($i = 1; $i <= 5; ++$i) {
            Cache::tags(["tag{$i}"])->flush();
        }

        // ZSETs should be deleted after flush
        for ($i = 1; $i <= 5; ++$i) {
            $this->assertEmpty($this->getAllModeTagEntries("tag{$i}"));
        }
    }

    // =========================================================================
    // REGISTRY CLEANUP (ANY MODE)
    // =========================================================================

    public function testAnyModePruneRemovesStaleTagsFromRegistry(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create items
        Cache::tags(['tag1', 'tag2'])->put('key1', 'value1', 60);

        // Verify tags are in registry
        $this->assertTrue($this->anyModeRegistryHasTag('tag1'));
        $this->assertTrue($this->anyModeRegistryHasTag('tag2'));

        // Flush both tags
        Cache::tags(['tag1', 'tag2'])->flush();

        // Tags should be removed from registry after flush
        $this->assertFalse($this->anyModeRegistryHasTag('tag1'));
        $this->assertFalse($this->anyModeRegistryHasTag('tag2'));
    }
}
