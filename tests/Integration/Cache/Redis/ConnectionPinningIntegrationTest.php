<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Support\Facades\Cache;

/**
 * Integration tests for Redis connection pinning.
 *
 * Verifies that withPinnedConnection() reuses the same pool connection
 * for multiple Redis operations, and that remember/rememberForever
 * benefit from pinning transparently.
 */
class ConnectionPinningIntegrationTest extends RedisCacheIntegrationTestCase
{
    public function testWithPinnedConnectionReusesConnection(): void
    {
        $store = $this->store();

        // Multiple operations inside a pinned scope should all succeed
        // using a single pool connection
        $result = $store->withPinnedConnection(function () use ($store) {
            $store->put('pinned_key_1', 'value_1', 60);
            $store->put('pinned_key_2', 'value_2', 60);

            return [
                $store->get('pinned_key_1'),
                $store->get('pinned_key_2'),
            ];
        });

        $this->assertSame(['value_1', 'value_2'], $result);
    }

    public function testWithPinnedConnectionIsReentrant(): void
    {
        $store = $this->store();

        $result = $store->withPinnedConnection(function () use ($store) {
            $store->put('outer_key', 'outer_value', 60);

            // Nested pin should not double-release
            return $store->withPinnedConnection(function () use ($store) {
                $store->put('inner_key', 'inner_value', 60);

                return $store->get('outer_key') . ':' . $store->get('inner_key');
            });
        });

        $this->assertSame('outer_value:inner_value', $result);

        // Both keys should still be accessible after the pinned scope
        $this->assertSame('outer_value', Cache::get('outer_key'));
        $this->assertSame('inner_value', Cache::get('inner_key'));
    }

    public function testRememberUsesPinnedConnectionTransparently(): void
    {
        // remember() should work correctly — the pinning happens inside Repository
        $callCount = 0;

        $result = Cache::remember('pinned_remember', 60, function () use (&$callCount) {
            ++$callCount;

            return 'computed_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);

        // Second call should return cached value
        $result = Cache::remember('pinned_remember', 60, function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('computed_value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testRememberForeverUsesPinnedConnectionTransparently(): void
    {
        $callCount = 0;

        $result = Cache::rememberForever('pinned_forever', function () use (&$callCount) {
            ++$callCount;

            return 'forever_value';
        });

        $this->assertSame('forever_value', $result);
        $this->assertSame(1, $callCount);

        // Second call should return cached value
        $result = Cache::rememberForever('pinned_forever', function () use (&$callCount) {
            ++$callCount;

            return 'new_value';
        });

        $this->assertSame('forever_value', $result);
        $this->assertSame(1, $callCount);
    }
}
