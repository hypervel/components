<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;
use Throwable;

/**
 * Integration tests for tag flush operations.
 *
 * Tests tag flush behavior for both modes:
 * - All mode: Items must be accessed with same tags they were stored with
 * - Any mode: Union flush - flushing ANY matching tag removes the item
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class FlushOperationsIntegrationTest extends CacheRedisIntegrationTestCase
{
    // =========================================================================
    // ANY MODE - UNION FLUSH BEHAVIOR
    // =========================================================================

    public function testAnyModeFlushesItemsByAnyTag(): void
    {
        $this->setTagMode(TagMode::Any);

        // Store items with different tag combinations
        Cache::tags(['posts', 'user:1'])->put('post.1', 'Post 1', 60);
        Cache::tags(['posts', 'featured'])->put('post.2', 'Post 2', 60);
        Cache::tags(['posts'])->put('post.3', 'Post 3', 60);
        Cache::tags(['videos', 'user:1'])->put('video.1', 'Video 1', 60);

        // Flushing 'posts' should remove all posts but not videos
        Cache::tags(['posts'])->flush();

        $this->assertNull(Cache::get('post.1'));
        $this->assertNull(Cache::get('post.2'));
        $this->assertNull(Cache::get('post.3'));
        $this->assertSame('Video 1', Cache::get('video.1'));
    }

    public function testAnyModeFlushesItemsWhenAnyTagMatches(): void
    {
        $this->setTagMode(TagMode::Any);

        // Item with multiple tags
        Cache::tags(['products', 'electronics', 'featured'])->put('laptop', 'MacBook', 60);
        Cache::tags(['products', 'clothing'])->put('shirt', 'T-Shirt', 60);

        // Flushing 'electronics' should only remove the laptop
        Cache::tags(['electronics'])->flush();

        $this->assertNull(Cache::get('laptop'));
        $this->assertSame('T-Shirt', Cache::get('shirt'));

        // Now flush 'products' - should remove the shirt
        Cache::tags(['products'])->flush();

        $this->assertNull(Cache::get('shirt'));
    }

    public function testAnyModeFlushMultipleTagsAsUnion(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['tag1'])->put('item1', 'value1', 60);
        Cache::tags(['tag2'])->put('item2', 'value2', 60);
        Cache::tags(['tag3'])->put('item3', 'value3', 60);

        // Flush items with tag1 OR tag2
        Cache::tags(['tag1', 'tag2'])->flush();

        $this->assertNull(Cache::get('item1'));
        $this->assertNull(Cache::get('item2'));
        $this->assertSame('value3', Cache::get('item3'));
    }

    public function testAnyModeRemovesTagFromRegistryWhenFlushed(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create items with tags
        Cache::tags(['tag-a', 'tag-b'])->put('item', 'value', 60);

        // Verify tags are in registry
        $this->assertTrue($this->anyModeRegistryHasTag('tag-a'));
        $this->assertTrue($this->anyModeRegistryHasTag('tag-b'));

        // Flush one tag
        Cache::tags(['tag-a'])->flush();

        // Verify tag-a is gone from registry
        $this->assertFalse($this->anyModeRegistryHasTag('tag-a'));

        // tag-b should still exist (it wasn't flushed and still has items referencing it)
        // Note: In lazy cleanup mode, tag-b may still be in registry until prune runs
    }

    public function testAnyModeRemovesItemWhenFlushingAnyOfItsTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Scenario 1: Flush first tag
        Cache::tags(['tag_a', 'tag_b'])->put('key_1', 'value_1', 60);
        $this->assertSame('value_1', Cache::get('key_1'));

        Cache::tags(['tag_a'])->flush();
        $this->assertNull(Cache::get('key_1'));

        // Scenario 2: Flush second tag
        Cache::tags(['tag_a', 'tag_b'])->put('key_2', 'value_2', 60);
        $this->assertSame('value_2', Cache::get('key_2'));

        Cache::tags(['tag_b'])->flush();
        $this->assertNull(Cache::get('key_2'));

        // Scenario 3: Flush unrelated tag should not affect item
        Cache::tags(['tag_a', 'tag_b'])->put('key_3', 'value_3', 60);
        $this->assertSame('value_3', Cache::get('key_3'));

        Cache::tags(['tag_c'])->flush();
        $this->assertSame('value_3', Cache::get('key_3'));
    }

    public function testAnyModeHandlesComplexTagIntersections(): void
    {
        $this->setTagMode(TagMode::Any);

        // Item 1: tags [A, B]
        Cache::tags(['A', 'B'])->put('item_1', 'val_1', 60);

        // Item 2: tags [B, C]
        Cache::tags(['B', 'C'])->put('item_2', 'val_2', 60);

        // Item 3: tags [A, C]
        Cache::tags(['A', 'C'])->put('item_3', 'val_3', 60);

        // Flush B
        Cache::tags(['B'])->flush();

        // Item 1 (A, B) -> Should be gone
        $this->assertNull(Cache::get('item_1'));

        // Item 2 (B, C) -> Should be gone
        $this->assertNull(Cache::get('item_2'));

        // Item 3 (A, C) -> Should remain (didn't have tag B)
        $this->assertSame('val_3', Cache::get('item_3'));
    }

    // =========================================================================
    // ALL MODE - FLUSH BEHAVIOR
    // =========================================================================

    public function testAllModeFlushRemovesItemsWithTag(): void
    {
        $this->setTagMode(TagMode::All);

        // Store items with different tag combinations
        Cache::tags(['posts'])->put('post.1', 'Post 1', 60);
        Cache::tags(['posts', 'featured'])->put('post.2', 'Post 2', 60);
        Cache::tags(['videos'])->put('video.1', 'Video 1', 60);

        // Flushing 'posts' should remove items tagged with 'posts'
        Cache::tags(['posts'])->flush();

        // Items that had 'posts' tag should be gone
        $this->assertNull(Cache::tags(['posts'])->get('post.1'));

        // Items with 'posts' + 'featured' are also removed (posts ZSET was flushed)
        $this->assertNull(Cache::tags(['posts', 'featured'])->get('post.2'));

        // Videos should remain
        $this->assertSame('Video 1', Cache::tags(['videos'])->get('video.1'));
    }

    public function testAllModeFlushMultipleTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['tag1'])->put('item1', 'value1', 60);
        Cache::tags(['tag2'])->put('item2', 'value2', 60);
        Cache::tags(['tag3'])->put('item3', 'value3', 60);

        // Flush tag1 and tag2
        Cache::tags(['tag1'])->flush();
        Cache::tags(['tag2'])->flush();

        $this->assertNull(Cache::tags(['tag1'])->get('item1'));
        $this->assertNull(Cache::tags(['tag2'])->get('item2'));
        $this->assertSame('value3', Cache::tags(['tag3'])->get('item3'));
    }

    public function testAllModeTagZsetIsDeletedOnFlush(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['posts'])->put('post.1', 'content', 60);

        // Verify ZSET exists
        $this->assertRedisKeyExists($this->allModeTagKey('posts'));

        // Flush
        Cache::tags(['posts'])->flush();

        // ZSET should be deleted
        $this->assertRedisKeyNotExists($this->allModeTagKey('posts'));
    }

    // =========================================================================
    // BOTH MODES - COMMON FLUSH BEHAVIOR
    // =========================================================================

    public function testFlushNonExistentTagGracefullyInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['real-tag'])->put('item', 'value', 60);

        // Flushing non-existent tag should not throw errors
        try {
            Cache::tags(['non-existent'])->flush();
            $this->assertTrue(true);
        } catch (Throwable $e) {
            $this->fail('Flushing non-existent tag should not throw: ' . $e->getMessage());
        }

        // Real item should still exist
        $this->assertSame('value', Cache::tags(['real-tag'])->get('item'));
    }

    public function testFlushNonExistentTagGracefullyInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['real-tag'])->put('item', 'value', 60);

        // Flushing non-existent tag should not throw errors
        try {
            Cache::tags(['non-existent'])->flush();
            $this->assertTrue(true);
        } catch (Throwable $e) {
            $this->fail('Flushing non-existent tag should not throw: ' . $e->getMessage());
        }

        // Real item should still exist
        $this->assertSame('value', Cache::get('item'));
    }

    public function testFlushLargeTagSetInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        // Create many items with the same tag
        for ($i = 0; $i < 100; ++$i) {
            Cache::tags(['bulk'])->put("item.{$i}", "value.{$i}", 60);
        }

        // Verify some items exist
        $this->assertSame('value.0', Cache::tags(['bulk'])->get('item.0'));
        $this->assertSame('value.50', Cache::tags(['bulk'])->get('item.50'));
        $this->assertSame('value.99', Cache::tags(['bulk'])->get('item.99'));

        // Flush all at once
        Cache::tags(['bulk'])->flush();

        // Verify all items are gone
        for ($i = 0; $i < 100; ++$i) {
            $this->assertNull(Cache::tags(['bulk'])->get("item.{$i}"));
        }
    }

    public function testFlushLargeTagSetInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        // Create many items with the same tag
        for ($i = 0; $i < 100; ++$i) {
            Cache::tags(['bulk'])->put("item.{$i}", "value.{$i}", 60);
        }

        // Verify some items exist
        $this->assertSame('value.0', Cache::get('item.0'));
        $this->assertSame('value.50', Cache::get('item.50'));
        $this->assertSame('value.99', Cache::get('item.99'));

        // Flush all at once
        Cache::tags(['bulk'])->flush();

        // Verify all items are gone
        for ($i = 0; $i < 100; ++$i) {
            $this->assertNull(Cache::get("item.{$i}"));
        }
    }

    public function testFlushDoesNotAffectUntaggedItemsInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        // Store some untagged items
        Cache::put('untagged.1', 'value1', 60);
        Cache::put('untagged.2', 'value2', 60);

        // Store some tagged items
        Cache::tags(['tagged'])->put('tagged.1', 'tagged1', 60);

        // Flush tagged items
        Cache::tags(['tagged'])->flush();

        // Untagged items should remain
        $this->assertSame('value1', Cache::get('untagged.1'));
        $this->assertSame('value2', Cache::get('untagged.2'));
        $this->assertNull(Cache::tags(['tagged'])->get('tagged.1'));
    }

    public function testFlushDoesNotAffectUntaggedItemsInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        // Store some untagged items
        Cache::put('untagged.1', 'value1', 60);
        Cache::put('untagged.2', 'value2', 60);

        // Store some tagged items
        Cache::tags(['tagged'])->put('tagged.1', 'tagged1', 60);

        // Flush tagged items
        Cache::tags(['tagged'])->flush();

        // Untagged items should remain
        $this->assertSame('value1', Cache::get('untagged.1'));
        $this->assertSame('value2', Cache::get('untagged.2'));
        $this->assertNull(Cache::get('tagged.1'));
    }

    public function testAnyModeTagHashIsDeletedOnFlush(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post.1', 'content', 60);

        // Verify HASH exists
        $this->assertRedisKeyExists($this->anyModeTagKey('posts'));

        // Flush
        Cache::tags(['posts'])->flush();

        // HASH should be deleted
        $this->assertRedisKeyNotExists($this->anyModeTagKey('posts'));
    }

    public function testAnyModeReverseIndexIsDeletedOnFlush(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['posts'])->put('post.1', 'content', 60);

        // Verify reverse index exists
        $this->assertRedisKeyExists($this->anyModeReverseIndexKey('post.1'));

        // Flush
        Cache::tags(['posts'])->flush();

        // Reverse index should be deleted
        $this->assertRedisKeyNotExists($this->anyModeReverseIndexKey('post.1'));
    }

    // =========================================================================
    // FLUSH WITH SHARED TAGS (ORPHAN CREATION IN LAZY MODE)
    // =========================================================================

    public function testAnyModeFlushCreatesOrphanedFieldsInOtherTags(): void
    {
        $this->setTagMode(TagMode::Any);

        // Item belongs to both alpha and beta tags
        Cache::tags(['alpha', 'beta'])->put('shared', 'value', 60);

        // Verify item is in both tag hashes
        $this->assertTrue($this->anyModeTagHasEntry('alpha', 'shared'));
        $this->assertTrue($this->anyModeTagHasEntry('beta', 'shared'));

        // Flush by alpha tag only
        Cache::tags(['alpha'])->flush();

        // Item should be gone from cache
        $this->assertNull(Cache::get('shared'));

        // Alpha hash should be deleted
        $this->assertRedisKeyNotExists($this->anyModeTagKey('alpha'));

        // In lazy mode, beta hash may still have an orphaned field
        // (this is expected behavior - prune command cleans these up)
        // The field will have expired TTL or the cache key won't exist
    }

    public function testAllModeFlushCreatesOrphanedEntriesInOtherTags(): void
    {
        $this->setTagMode(TagMode::All);

        // Item belongs to both alpha and beta tags
        Cache::tags(['alpha', 'beta'])->put('shared', 'value', 60);

        // Verify item is in both tag ZSETs
        $this->assertNotEmpty($this->getAllModeTagEntries('alpha'));
        $this->assertNotEmpty($this->getAllModeTagEntries('beta'));

        // Flush by alpha tag only
        Cache::tags(['alpha'])->flush();

        // Alpha ZSET should be deleted
        $this->assertRedisKeyNotExists($this->allModeTagKey('alpha'));

        // In lazy mode, beta ZSET may still have an orphaned entry
        // (this is expected behavior - prune command cleans these up)
    }
}
