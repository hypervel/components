<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use BadMethodCallException;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for blocked operations in any mode (ANY MODE ONLY).
 *
 * In any mode, certain operations that would require tag-based lookup
 * are not supported because tags are used only for write operations
 * and invalidation, not for retrieval.
 *
 * Blocked operations:
 * - get() via tags
 * - many() via tags
 * - has() via tags
 * - pull() via tags
 * - forget() via tags
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class BlockedOperationsIntegrationTest extends CacheRedisIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setTagMode(TagMode::Any);
    }

    // =========================================================================
    // GET OPERATIONS
    // =========================================================================

    public function testGetViaTagsThrowsException(): void
    {
        // First store an item with a tag
        Cache::tags(['blocked_tag'])->put('blocked_key', 'value', 60);

        // Verify the item exists via non-tagged get
        $this->assertSame('value', Cache::get('blocked_key'));

        // Attempting to get via tags should throw
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        Cache::tags(['blocked_tag'])->get('blocked_key');
    }

    public function testGetViaTagsReturnsDefaultInsteadOfThrowing(): void
    {
        // This test documents that get() with default throws regardless
        Cache::tags(['blocked_tag'])->put('blocked_key', 'value', 60);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        Cache::tags(['blocked_tag'])->get('blocked_key', 'default_value');
    }

    public function testManyViaTagsThrowsException(): void
    {
        Cache::tags(['blocked_tag'])->put('key1', 'value1', 60);
        Cache::tags(['blocked_tag'])->put('key2', 'value2', 60);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot get items via tags in any mode');

        Cache::tags(['blocked_tag'])->many(['key1', 'key2']);
    }

    // =========================================================================
    // HAS OPERATION
    // =========================================================================

    public function testHasViaTagsThrowsException(): void
    {
        Cache::tags(['blocked_tag'])->put('blocked_key', 'value', 60);

        // Verify via non-tagged has
        $this->assertTrue(Cache::has('blocked_key'));

        // Attempting to check via tags should throw
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot check existence via tags in any mode');

        Cache::tags(['blocked_tag'])->has('blocked_key');
    }

    public function testMissingViaTagsThrowsException(): void
    {
        // missing() is the inverse of has()
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot check existence via tags in any mode');

        Cache::tags(['blocked_tag'])->missing('nonexistent_key');
    }

    // =========================================================================
    // PULL OPERATION
    // =========================================================================

    public function testPullViaTagsThrowsException(): void
    {
        Cache::tags(['blocked_tag'])->put('blocked_key', 'value', 60);

        // Verify the item exists
        $this->assertSame('value', Cache::get('blocked_key'));

        // Attempting to pull via tags should throw
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot pull items via tags in any mode');

        Cache::tags(['blocked_tag'])->pull('blocked_key');
    }

    // =========================================================================
    // FORGET OPERATION
    // =========================================================================

    public function testForgetViaTagsThrowsException(): void
    {
        Cache::tags(['blocked_tag'])->put('blocked_key', 'value', 60);

        // Verify the item exists
        $this->assertSame('value', Cache::get('blocked_key'));

        // Attempting to forget via tags should throw
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot forget items via tags in any mode');

        Cache::tags(['blocked_tag'])->forget('blocked_key');
    }

    // =========================================================================
    // WORKAROUND TESTS - DOCUMENT CORRECT PATTERNS
    // =========================================================================

    public function testCorrectPatternForGettingItems(): void
    {
        Cache::tags(['correct_tag'])->put('correct_key', 'correct_value', 60);

        // Correct way: get via Cache directly without tags
        $this->assertSame('correct_value', Cache::get('correct_key'));
    }

    public function testCorrectPatternForCheckingExistence(): void
    {
        Cache::tags(['correct_tag'])->put('correct_key', 'correct_value', 60);

        // Correct way: check via Cache directly without tags
        $this->assertTrue(Cache::has('correct_key'));
    }

    public function testCorrectPatternForRemovingItem(): void
    {
        Cache::tags(['correct_tag'])->put('correct_key', 'correct_value', 60);

        // Correct way: forget via Cache directly without tags
        $this->assertTrue(Cache::forget('correct_key'));
        $this->assertNull(Cache::get('correct_key'));
    }

    public function testCorrectPatternForFlushingByTag(): void
    {
        Cache::tags(['flush_tag'])->put('key1', 'value1', 60);
        Cache::tags(['flush_tag'])->put('key2', 'value2', 60);
        Cache::tags(['other_tag'])->put('key3', 'value3', 60);

        // Correct way: flush entire tag (removes all items with that tag)
        Cache::tags(['flush_tag'])->flush();

        $this->assertNull(Cache::get('key1'));
        $this->assertNull(Cache::get('key2'));
        // key3 was not in flush_tag, so it remains
        $this->assertSame('value3', Cache::get('key3'));
    }

    public function testItemsMethodWorksForQueryingTaggedItems(): void
    {
        Cache::tags(['query_tag'])->put('item1', 'value1', 60);
        Cache::tags(['query_tag'])->put('item2', 'value2', 60);

        // Correct way to query what's in a tag: use items()
        $items = iterator_to_array(Cache::tags(['query_tag'])->items());

        $this->assertCount(2, $items);
        $this->assertArrayHasKey('item1', $items);
        $this->assertArrayHasKey('item2', $items);
        $this->assertSame('value1', $items['item1']);
        $this->assertSame('value2', $items['item2']);
    }

    // =========================================================================
    // ALL MODE DOES NOT BLOCK THESE OPERATIONS
    // =========================================================================

    public function testAllModeAllowsGetViaTags(): void
    {
        // Switch to all mode
        $this->setTagMode(TagMode::All);

        Cache::tags(['allowed_tag'])->put('allowed_key', 'allowed_value', 60);

        // All mode allows get via tags
        $this->assertSame('allowed_value', Cache::tags(['allowed_tag'])->get('allowed_key'));
    }

    public function testAllModeAllowsHasViaTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['allowed_tag'])->put('allowed_key', 'allowed_value', 60);

        // All mode allows has via tags
        $this->assertTrue(Cache::tags(['allowed_tag'])->has('allowed_key'));
    }

    public function testAllModeAllowsForgetViaTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['allowed_tag'])->put('allowed_key', 'allowed_value', 60);

        // All mode allows forget via tags
        $this->assertTrue(Cache::tags(['allowed_tag'])->forget('allowed_key'));
        $this->assertNull(Cache::tags(['allowed_tag'])->get('allowed_key'));
    }

    public function testAllModeAllowsPullViaTags(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['allowed_tag'])->put('allowed_key', 'allowed_value', 60);

        // All mode allows pull via tags
        $this->assertSame('allowed_value', Cache::tags(['allowed_tag'])->pull('allowed_key'));
        $this->assertNull(Cache::tags(['allowed_tag'])->get('allowed_key'));
    }
}
