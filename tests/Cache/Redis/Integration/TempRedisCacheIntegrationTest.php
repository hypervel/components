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

    /**
     * Tests below are designed to FAIL if parallel workers share the same key space.
     * They use predictable key names that would collide without proper isolation.
     */

    /**
     * Test that a worker's unique value is not overwritten by another worker.
     *
     * If isolation fails, another worker writing to 'isolation_test' would
     * overwrite this worker's value, causing the assertion to fail.
     */
    public function testParallelIsolationUniqueValue(): void
    {
        $key = 'isolation_test';
        $uniqueValue = 'worker_' . ($this->cachePrefix) . '_' . uniqid();

        Cache::put($key, $uniqueValue, 60);

        // Small delay to allow potential interference from other workers
        usleep(50000); // 50ms

        $retrieved = Cache::get($key);
        $this->assertSame(
            $uniqueValue,
            $retrieved,
            "Value was modified by another worker. Expected '{$uniqueValue}', got '{$retrieved}'. " .
            'This indicates key isolation is not working properly.'
        );
    }

    /**
     * Test that increment operations are isolated per worker.
     *
     * If isolation fails, multiple workers incrementing 'counter_test'
     * would result in a value higher than expected.
     */
    public function testParallelIsolationCounter(): void
    {
        $key = 'counter_test';
        $increments = 5;

        // Start fresh
        Cache::forget($key);

        // Increment the counter multiple times
        for ($i = 0; $i < $increments; $i++) {
            Cache::increment($key);
            usleep(10000); // 10ms delay between increments
        }

        $finalValue = (int) Cache::get($key);
        $this->assertSame(
            $increments,
            $finalValue,
            "Counter value was {$finalValue}, expected {$increments}. " .
            'Another worker may have incremented the same key. ' .
            'This indicates key isolation is not working properly.'
        );
    }

    /**
     * Test that cache operations within a sequence remain consistent.
     *
     * If isolation fails, another worker's put/forget operations on the
     * same key would interfere with this test's sequence.
     */
    public function testParallelIsolationSequence(): void
    {
        $key = 'sequence_test';

        // Sequence: put -> verify -> forget -> verify null -> put again -> verify
        Cache::put($key, 'step1', 60);
        usleep(20000);
        $this->assertSame('step1', Cache::get($key), 'Step 1 failed');

        Cache::forget($key);
        usleep(20000);
        $this->assertNull(Cache::get($key), 'Step 2 failed - key should be null after forget');

        Cache::put($key, 'step3', 60);
        usleep(20000);
        $this->assertSame('step3', Cache::get($key), 'Step 3 failed');
    }

    /**
     * Test that multiple keys remain isolated and consistent.
     *
     * If isolation fails, another worker operating on the same key names
     * would cause value mismatches.
     */
    public function testParallelIsolationMultipleKeys(): void
    {
        $keys = [
            'multi_key_a' => 'value_a_' . uniqid(),
            'multi_key_b' => 'value_b_' . uniqid(),
            'multi_key_c' => 'value_c_' . uniqid(),
        ];

        // Store all keys
        foreach ($keys as $key => $value) {
            Cache::put($key, $value, 60);
        }

        usleep(50000); // 50ms delay

        // Verify all keys still have correct values
        foreach ($keys as $key => $expectedValue) {
            $actualValue = Cache::get($key);
            $this->assertSame(
                $expectedValue,
                $actualValue,
                "Key '{$key}' was modified. Expected '{$expectedValue}', got '{$actualValue}'. " .
                'This indicates key isolation is not working properly.'
            );
        }
    }

    /**
     * Intensive test: rapid writes to same key name across iterations.
     *
     * If isolation fails, values from other workers would appear.
     */
    public function testParallelIsolationRapidWrites(): void
    {
        $key = 'rapid_write_test';
        $workerIdentifier = $this->cachePrefix . uniqid();

        for ($i = 0; $i < 20; $i++) {
            $value = "{$workerIdentifier}_{$i}";
            Cache::put($key, $value, 60);
            usleep(5000); // 5ms

            $retrieved = Cache::get($key);
            $this->assertSame(
                $value,
                $retrieved,
                "Iteration {$i}: Expected '{$value}', got '{$retrieved}'. Collision detected."
            );
        }
    }

    /**
     * Intensive test: increment race condition.
     *
     * Each worker increments 50 times. If isolated, final value is 50.
     * If not isolated, final value would be higher (multiple workers adding).
     */
    public function testParallelIsolationIncrementRace(): void
    {
        $key = 'increment_race_test';
        $iterations = 50;

        Cache::forget($key);

        for ($i = 0; $i < $iterations; $i++) {
            Cache::increment($key);
        }

        $finalValue = (int) Cache::get($key);
        $this->assertSame(
            $iterations,
            $finalValue,
            "Expected {$iterations}, got {$finalValue}. Other workers may have incremented same key."
        );
    }

    /**
     * Test that tagged cache operations are also isolated.
     */
    public function testParallelIsolationTaggedCache(): void
    {
        $tag = 'isolation_tag';
        $key = 'tagged_key';
        $value = 'tagged_value_' . $this->cachePrefix . uniqid();

        Cache::tags([$tag])->put($key, $value, 60);
        usleep(30000); // 30ms

        $retrieved = Cache::tags([$tag])->get($key);
        $this->assertSame(
            $value,
            $retrieved,
            "Tagged cache value mismatch. Expected '{$value}', got '{$retrieved}'."
        );
    }
}
