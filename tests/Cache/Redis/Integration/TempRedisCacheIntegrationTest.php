<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Redis;

/**
 * Temporary integration test to verify Redis cache infrastructure works.
 *
 * This test verifies that:
 * 1. Cache::put() stores data in Redis
 * 2. Redis::get() can retrieve the cached data directly
 * 3. Cache::get() retrieves the data correctly
 *
 * @internal
 * @coversNothing
 */
class TempRedisCacheIntegrationTest extends RedisIntegrationTestCase
{
    public function testCachePutStoresValueInRedis(): void
    {
        $key = 'test_key';
        $value = 'test_value';

        // Store via Cache facade
        Cache::put($key, $value, 60);

        // Verify it can be retrieved via Cache facade
        $cachedValue = Cache::get($key);
        $this->assertSame($value, $cachedValue);

        // Verify the key exists in Redis directly
        // The cache prefix is applied, so we need to check with the full key
        $redisKey = $this->cachePrefix . $key;
        $redisValue = Redis::get($redisKey);

        // Redis stores serialized values, so we unserialize
        $this->assertNotNull($redisValue, "Key '{$redisKey}' should exist in Redis");
    }

    public function testCacheForgetRemovesValueFromRedis(): void
    {
        $key = 'forget_test_key';
        $value = 'forget_test_value';

        // Store and verify
        Cache::put($key, $value, 60);
        $this->assertSame($value, Cache::get($key));

        // Forget and verify
        Cache::forget($key);
        $this->assertNull(Cache::get($key));
    }

    public function testRedisConnectionIsWorking(): void
    {
        // Simple ping test to verify Redis connection
        $result = Redis::ping();

        $this->assertTrue($result === true || $result === '+PONG' || $result === 'PONG');
    }
}
