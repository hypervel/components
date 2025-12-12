<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Support\Facades\Cache;
use RuntimeException;

/**
 * Integration tests for remember/rememberForever operations.
 *
 * Tests for both tag modes:
 * - Callback execution on cache miss
 * - Cache hit returns cached value (callback not called)
 * - Multiple tags
 * - Exception propagation
 * - Edge case return values (null, false, empty string, zero)
 *
 * @group redis-integration
 *
 * @internal
 * @coversNothing
 */
class RememberIntegrationTest extends CacheRedisIntegrationTestCase
{
    // =========================================================================
    // ALL MODE - REMEMBER OPERATIONS
    // =========================================================================

    public function testAllModeRemembersValueWithTags(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['remember_tag'])->remember('remember_key', 60, fn () => 'computed_value');

        $this->assertSame('computed_value', $result);
        $this->assertSame('computed_value', Cache::tags(['remember_tag'])->get('remember_key'));

        // Verify flush works
        Cache::tags(['remember_tag'])->flush();
        $this->assertNull(Cache::tags(['remember_tag'])->get('remember_key'));
    }

    public function testAllModeReturnsCachedValueOnSecondCall(): void
    {
        $this->setTagMode(TagMode::All);

        // First call
        Cache::tags(['hit_tag'])->remember('hit_key', 60, fn () => 'value_1');

        // Second call should return cached value, not execute closure
        $result = Cache::tags(['hit_tag'])->remember('hit_key', 60, fn () => 'value_2');

        $this->assertSame('value_1', $result);
    }

    public function testAllModeRemembersForever(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['forever_tag'])->rememberForever('forever_key', fn () => 'forever_value');

        $this->assertSame('forever_value', $result);
        $this->assertSame('forever_value', Cache::tags(['forever_tag'])->get('forever_key'));

        Cache::tags(['forever_tag'])->flush();
        $this->assertNull(Cache::tags(['forever_tag'])->get('forever_key'));
    }

    public function testAllModeRemembersWithMultipleTags(): void
    {
        $this->setTagMode(TagMode::All);
        $tags = ['tag1', 'tag2', 'tag3'];

        $result = Cache::tags($tags)->remember('multi_tag_key', 60, fn () => 'multi_tag_value');

        $this->assertSame('multi_tag_value', $result);
        $this->assertSame('multi_tag_value', Cache::tags($tags)->get('multi_tag_key'));

        // Flush one tag should remove it
        Cache::tags(['tag2'])->flush();
        $this->assertNull(Cache::tags($tags)->get('multi_tag_key'));
    }

    // =========================================================================
    // ANY MODE - REMEMBER OPERATIONS
    // =========================================================================

    public function testAnyModeRemembersValueWithTags(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['remember_tag'])->remember('remember_key', 60, fn () => 'computed_value');

        $this->assertSame('computed_value', $result);
        $this->assertSame('computed_value', Cache::get('remember_key'));

        // Verify flush works
        Cache::tags(['remember_tag'])->flush();
        $this->assertNull(Cache::get('remember_key'));
    }

    public function testAnyModeReturnsCachedValueOnSecondCall(): void
    {
        $this->setTagMode(TagMode::Any);

        // First call
        Cache::tags(['hit_tag'])->remember('hit_key', 60, fn () => 'value_1');

        // Second call should return cached value, not execute closure
        $result = Cache::tags(['hit_tag'])->remember('hit_key', 60, fn () => 'value_2');

        $this->assertSame('value_1', $result);
    }

    public function testAnyModeRemembersForever(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['forever_tag'])->rememberForever('forever_key', fn () => 'forever_value');

        $this->assertSame('forever_value', $result);
        $this->assertSame('forever_value', Cache::get('forever_key'));

        Cache::tags(['forever_tag'])->flush();
        $this->assertNull(Cache::get('forever_key'));
    }

    public function testAnyModeRemembersWithMultipleTags(): void
    {
        $this->setTagMode(TagMode::Any);
        $tags = ['tag1', 'tag2', 'tag3'];

        $result = Cache::tags($tags)->remember('multi_tag_key', 60, fn () => 'multi_tag_value');

        $this->assertSame('multi_tag_value', $result);
        $this->assertSame('multi_tag_value', Cache::get('multi_tag_key'));

        // Flush one tag should remove it (union behavior)
        Cache::tags(['tag2'])->flush();
        $this->assertNull(Cache::get('multi_tag_key'));
    }

    // =========================================================================
    // CALLBACK NOT CALLED WHEN VALUE EXISTS - BOTH MODES
    // =========================================================================

    public function testAllModeDoesNotCallCallbackWhenValueExists(): void
    {
        $this->setTagMode(TagMode::All);

        $callCount = 0;
        Cache::tags(['existing_tag'])->remember('existing_key', 60, function () use (&$callCount) {
            ++$callCount;

            return 'first';
        });
        $this->assertEquals(1, $callCount);

        // Second call should NOT invoke callback
        $result = Cache::tags(['existing_tag'])->remember('existing_key', 60, function () use (&$callCount) {
            ++$callCount;

            return 'second';
        });

        $this->assertEquals(1, $callCount); // Still 1
        $this->assertSame('first', $result);
    }

    public function testAnyModeDoesNotCallCallbackWhenValueExists(): void
    {
        $this->setTagMode(TagMode::Any);

        $callCount = 0;
        Cache::tags(['existing_tag'])->remember('existing_key', 60, function () use (&$callCount) {
            ++$callCount;

            return 'first';
        });
        $this->assertEquals(1, $callCount);

        // Second call should NOT invoke callback
        $result = Cache::tags(['existing_tag'])->remember('existing_key', 60, function () use (&$callCount) {
            ++$callCount;

            return 'second';
        });

        $this->assertEquals(1, $callCount); // Still 1
        $this->assertSame('first', $result);
    }

    // =========================================================================
    // RE-EXECUTES CLOSURE AFTER FLUSH - BOTH MODES
    // =========================================================================

    public function testAllModeReExecutesClosureAfterFlush(): void
    {
        $this->setTagMode(TagMode::All);
        $tags = ['tag1', 'tag2'];

        // 1. Remember (Miss)
        $result = Cache::tags($tags)->remember('lifecycle_key', 60, fn () => 'val_1');
        $this->assertSame('val_1', $result);

        // 2. Remember (Hit)
        $result = Cache::tags($tags)->remember('lifecycle_key', 60, fn () => 'val_2');
        $this->assertSame('val_1', $result);

        // 3. Flush tag1
        Cache::tags(['tag1'])->flush();

        // 4. Remember (Miss - because key was deleted)
        $result = Cache::tags($tags)->remember('lifecycle_key', 60, fn () => 'val_3');
        $this->assertSame('val_3', $result);
    }

    public function testAnyModeReExecutesClosureAfterFlush(): void
    {
        $this->setTagMode(TagMode::Any);
        $tags = ['tag1', 'tag2'];

        // 1. Remember (Miss)
        $result = Cache::tags($tags)->remember('lifecycle_key', 60, fn () => 'val_1');
        $this->assertSame('val_1', $result);

        // 2. Remember (Hit)
        $result = Cache::tags($tags)->remember('lifecycle_key', 60, fn () => 'val_2');
        $this->assertSame('val_1', $result);

        // 3. Flush tag1
        Cache::tags(['tag1'])->flush();

        // 4. Remember (Miss - because key was deleted)
        $result = Cache::tags($tags)->remember('lifecycle_key', 60, fn () => 'val_3');
        $this->assertSame('val_3', $result);
    }

    // =========================================================================
    // EXCEPTION PROPAGATION - BOTH MODES
    // =========================================================================

    public function testAllModePropagatesExceptionFromRememberCallback(): void
    {
        $this->setTagMode(TagMode::All);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        Cache::tags(['exception_tag'])->remember('exception_key', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testAnyModePropagatesExceptionFromRememberCallback(): void
    {
        $this->setTagMode(TagMode::Any);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        Cache::tags(['exception_tag'])->remember('exception_key', 60, function () {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testAllModePropagatesExceptionFromRememberForeverCallback(): void
    {
        $this->setTagMode(TagMode::All);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        Cache::tags(['forever_exception_tag'])->rememberForever('forever_exception_key', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    public function testAnyModePropagatesExceptionFromRememberForeverCallback(): void
    {
        $this->setTagMode(TagMode::Any);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forever callback failed');

        Cache::tags(['forever_exception_tag'])->rememberForever('forever_exception_key', function () {
            throw new RuntimeException('Forever callback failed');
        });
    }

    // =========================================================================
    // EDGE CASE RETURN VALUES - BOTH MODES
    // =========================================================================

    public function testAllModeHandlesFalseReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['false_tag'])->remember('false_key', 60, fn () => false);

        $this->assertFalse($result);
        $this->assertFalse(Cache::tags(['false_tag'])->get('false_key'));
    }

    public function testAnyModeHandlesFalseReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['false_tag'])->remember('false_key', 60, fn () => false);

        $this->assertFalse($result);
        $this->assertFalse(Cache::get('false_key'));
    }

    public function testAllModeHandlesEmptyStringReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['empty_tag'])->remember('empty_key', 60, fn () => '');

        $this->assertSame('', $result);
        $this->assertSame('', Cache::tags(['empty_tag'])->get('empty_key'));
    }

    public function testAnyModeHandlesEmptyStringReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['empty_tag'])->remember('empty_key', 60, fn () => '');

        $this->assertSame('', $result);
        $this->assertSame('', Cache::get('empty_key'));
    }

    public function testAllModeHandlesZeroReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['zero_tag'])->remember('zero_key', 60, fn () => 0);

        $this->assertEquals(0, $result);
        $this->assertEquals(0, Cache::tags(['zero_tag'])->get('zero_key'));
    }

    public function testAnyModeHandlesZeroReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['zero_tag'])->remember('zero_key', 60, fn () => 0);

        $this->assertEquals(0, $result);
        $this->assertEquals(0, Cache::get('zero_key'));
    }

    public function testAllModeHandlesEmptyArrayReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::tags(['array_tag'])->remember('array_key', 60, fn () => []);

        $this->assertSame([], $result);
        $this->assertSame([], Cache::tags(['array_tag'])->get('array_key'));
    }

    public function testAnyModeHandlesEmptyArrayReturnFromRemember(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::tags(['array_tag'])->remember('array_key', 60, fn () => []);

        $this->assertSame([], $result);
        $this->assertSame([], Cache::get('array_key'));
    }

    // =========================================================================
    // NON-TAGGED REMEMBER OPERATIONS - BOTH MODES
    // =========================================================================

    public function testNonTaggedRememberInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::remember('untagged_remember', 60, fn () => 'untagged_value');

        $this->assertSame('untagged_value', $result);
        $this->assertSame('untagged_value', Cache::get('untagged_remember'));
    }

    public function testNonTaggedRememberInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::remember('untagged_remember', 60, fn () => 'untagged_value');

        $this->assertSame('untagged_value', $result);
        $this->assertSame('untagged_value', Cache::get('untagged_remember'));
    }

    public function testNonTaggedRememberForeverInAllMode(): void
    {
        $this->setTagMode(TagMode::All);

        $result = Cache::rememberForever('untagged_forever', fn () => 'forever_untagged');

        $this->assertSame('forever_untagged', $result);
        $this->assertSame('forever_untagged', Cache::get('untagged_forever'));
    }

    public function testNonTaggedRememberForeverInAnyMode(): void
    {
        $this->setTagMode(TagMode::Any);

        $result = Cache::rememberForever('untagged_forever', fn () => 'forever_untagged');

        $this->assertSame('forever_untagged', $result);
        $this->assertSame('forever_untagged', Cache::get('untagged_forever'));
    }
}
