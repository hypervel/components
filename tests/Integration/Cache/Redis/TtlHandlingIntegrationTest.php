<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Carbon\Carbon;
use DateInterval;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for TTL handling.
 *
 * Tests various TTL formats for both tag modes:
 * - Integer seconds
 * - DateTime objects
 * - DateInterval objects
 * - Very short TTL (1s)
 * - Large TTL (1 year)
 * - Forever (no expiration)
 *
 * @internal
 * @coversNothing
 */
class TtlHandlingIntegrationTest extends RedisCacheIntegrationTestCase
{
    // =========================================================================
    // INTEGER SECONDS TTL - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesIntegerSecondsttl(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['seconds_ttl'])->put('key', 'value', 60);

        $this->assertSame('value', Cache::tags(['seconds_ttl'])->get('key'));

        // Verify TTL is approximately correct
        $prefix = $this->getCachePrefix();
        // In all mode, key is namespaced - but we can check via the tag ZSET score
        $entries = $this->getAllModeTagEntries('seconds_ttl');
        $score = (int) reset($entries);

        // Score should be approximately now + 60 seconds
        $this->assertGreaterThan(time() + 50, $score);
        $this->assertLessThanOrEqual(time() + 61, $score);
    }

    public function testAnyModeHandlesIntegerSecondsTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['seconds_ttl'])->put('key', 'value', 60);

        $this->assertSame('value', Cache::get('key'));

        // Verify TTL is approximately correct
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'key');

        $this->assertGreaterThan(50, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    // =========================================================================
    // DATETIME TTL - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesDateTimeTtl(): void
    {
        $this->setTagMode(TagMode::All);

        $expires = Carbon::now()->addSeconds(60);
        Cache::tags(['datetime_ttl'])->put('datetime_key', 'datetime_value', $expires);

        $this->assertSame('datetime_value', Cache::tags(['datetime_ttl'])->get('datetime_key'));

        // Verify via ZSET score
        $entries = $this->getAllModeTagEntries('datetime_ttl');
        $score = (int) reset($entries);

        $this->assertGreaterThan(time() + 50, $score);
        $this->assertLessThanOrEqual(time() + 61, $score);
    }

    public function testAnyModeHandlesDateTimeTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        $expires = Carbon::now()->addSeconds(60);
        Cache::tags(['datetime_ttl'])->put('datetime_key', 'datetime_value', $expires);

        $this->assertSame('datetime_value', Cache::get('datetime_key'));

        // Verify TTL is approximately correct
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'datetime_key');

        $this->assertGreaterThan(50, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    // =========================================================================
    // DATEINTERVAL TTL - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesDateIntervalTtl(): void
    {
        $this->setTagMode(TagMode::All);

        $interval = new DateInterval('PT60S'); // 60 seconds
        Cache::tags(['interval_ttl'])->put('interval_key', 'interval_value', $interval);

        $this->assertSame('interval_value', Cache::tags(['interval_ttl'])->get('interval_key'));

        // Verify via ZSET score
        $entries = $this->getAllModeTagEntries('interval_ttl');
        $score = (int) reset($entries);

        $this->assertGreaterThan(time() + 50, $score);
        $this->assertLessThanOrEqual(time() + 61, $score);
    }

    public function testAnyModeHandlesDateIntervalTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        $interval = new DateInterval('PT60S'); // 60 seconds
        Cache::tags(['interval_ttl'])->put('interval_key', 'interval_value', $interval);

        $this->assertSame('interval_value', Cache::get('interval_key'));

        // Verify TTL is approximately correct
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'interval_key');

        $this->assertGreaterThan(50, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    // =========================================================================
    // VERY SHORT TTL - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesVeryShortTtl(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['short_ttl'])->put('short_key', 'short_value', 1);

        // Should exist immediately
        $this->assertSame('short_value', Cache::tags(['short_ttl'])->get('short_key'));

        // Wait for expiration
        sleep(2);

        // Should be expired
        $this->assertNull(Cache::tags(['short_ttl'])->get('short_key'));
    }

    public function testAnyModeHandlesVeryShortTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['short_ttl'])->put('short_key', 'short_value', 1);

        // Should exist immediately
        $this->assertSame('short_value', Cache::get('short_key'));

        // Wait for expiration
        sleep(2);

        // Should be expired
        $this->assertNull(Cache::get('short_key'));
    }

    // =========================================================================
    // LARGE TTL - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesLargeTtl(): void
    {
        $this->setTagMode(TagMode::All);

        $oneYear = 365 * 24 * 60 * 60;
        Cache::tags(['large_ttl'])->put('long_key', 'long_value', $oneYear);

        $this->assertSame('long_value', Cache::tags(['large_ttl'])->get('long_key'));

        // Verify via ZSET score
        $entries = $this->getAllModeTagEntries('large_ttl');
        $score = (int) reset($entries);

        // Score should be approximately now + 1 year
        $this->assertGreaterThan(time() + $oneYear - 10, $score);
        $this->assertLessThanOrEqual(time() + $oneYear + 1, $score);
    }

    public function testAnyModeHandlesLargeTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        $oneYear = 365 * 24 * 60 * 60;
        Cache::tags(['large_ttl'])->put('long_key', 'long_value', $oneYear);

        $this->assertSame('long_value', Cache::get('long_key'));

        // Verify TTL is close to 1 year
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'long_key');

        $this->assertGreaterThan($oneYear - 10, $ttl);
        $this->assertLessThanOrEqual($oneYear, $ttl);
    }

    // =========================================================================
    // FOREVER (NO EXPIRATION) - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesForeverTtl(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::tags(['forever_test'])->forever('forever_item', 'forever_content');

        $this->assertSame('forever_content', Cache::tags(['forever_test'])->get('forever_item'));

        // In all mode, forever items have score -1 in ZSET
        $entries = $this->getAllModeTagEntries('forever_test');
        $score = (int) reset($entries);

        $this->assertEquals(-1, $score);
    }

    public function testAnyModeHandlesForeverTtl(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::tags(['forever_test'])->forever('forever_item', 'forever_content');

        $this->assertSame('forever_content', Cache::get('forever_item'));

        // Verify TTL is -1 (no expiration)
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'forever_item');

        $this->assertEquals(-1, $ttl);
    }

    // =========================================================================
    // TTL UPDATE BEHAVIOR - BOTH MODES
    // =========================================================================

    public function testAllModeUpdatesTtlOnOverwrite(): void
    {
        $this->setTagMode(TagMode::All);

        // Store with 60 second TTL
        Cache::tags(['update_ttl'])->put('update_key', 'original', 60);

        $entriesBefore = $this->getAllModeTagEntries('update_ttl');
        $scoreBefore = (int) reset($entriesBefore);

        // Store again with 30 second TTL
        Cache::tags(['update_ttl'])->put('update_key', 'updated', 30);

        $this->assertSame('updated', Cache::tags(['update_ttl'])->get('update_key'));

        // Score should be updated to new TTL (approximately now + 30)
        $entriesAfter = $this->getAllModeTagEntries('update_ttl');
        $scoreAfter = (int) reset($entriesAfter);

        $this->assertLessThan($scoreBefore, $scoreAfter);
        $this->assertGreaterThan(time() + 20, $scoreAfter);
        $this->assertLessThanOrEqual(time() + 31, $scoreAfter);
    }

    public function testAnyModeUpdatesTtlOnOverwrite(): void
    {
        $this->setTagMode(TagMode::Any);

        // Store with 60 second TTL
        Cache::tags(['update_ttl'])->put('update_key', 'original', 60);

        // Store again with 30 second TTL
        Cache::tags(['update_ttl'])->put('update_key', 'updated', 30);

        $this->assertSame('updated', Cache::get('update_key'));

        // TTL should be updated to 30 seconds
        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'update_key');

        $this->assertLessThanOrEqual(30, $ttl);
        $this->assertGreaterThan(20, $ttl);
    }

    // =========================================================================
    // NON-TAGGED TTL - BOTH MODES
    // =========================================================================

    public function testNonTaggedTtlInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::put('untagged_key', 'value', 60);

        $this->assertSame('value', Cache::get('untagged_key'));

        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'untagged_key');

        $this->assertGreaterThan(50, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function testNonTaggedTtlInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::put('untagged_key', 'value', 60);

        $this->assertSame('value', Cache::get('untagged_key'));

        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'untagged_key');

        $this->assertGreaterThan(50, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function testNonTaggedForeverInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        Cache::forever('untagged_forever', 'eternal');

        $this->assertSame('eternal', Cache::get('untagged_forever'));

        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'untagged_forever');

        $this->assertEquals(-1, $ttl);
    }

    public function testNonTaggedForeverInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        Cache::forever('untagged_forever', 'eternal');

        $this->assertSame('eternal', Cache::get('untagged_forever'));

        $ttl = $this->redis()->ttl($this->getCachePrefix() . 'untagged_forever');

        $this->assertEquals(-1, $ttl);
    }
}
