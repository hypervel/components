<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Support\Facades\Redis;
use Throwable;

/**
 * Provides Redis integration testing support with parallel test isolation.
 *
 * This trait enables safe Redis integration testing by:
 * - Using TEST_TOKEN env var (from paratest) to create unique prefixes per worker
 * - Assigning unique Redis database numbers (8-15) based on TEST_TOKEN
 * - Flushing only keys matching the test prefix, not the entire database
 *
 * Usage:
 * 1. Use this trait in your test case
 * 2. Call computeRedisTestConfig() in setUp() before parent::setUp()
 * 3. Call configureRedisConnection() after parent::setUp()
 * 4. Optionally call flushRedisTestKeys() to clean up test data
 *
 * @property \Hypervel\Foundation\Application $app
 */
trait InteractsWithRedis
{
    /**
     * Base Redis database number for integration tests.
     * Will be offset by TEST_TOKEN for parallel execution (range: 8-15).
     */
    protected int $redisTestBaseDatabase = 8;

    /**
     * Base cache key prefix for integration tests.
     * Will include TEST_TOKEN for parallel execution.
     */
    protected string $redisTestBasePrefix = 'int_test';

    /**
     * Computed Redis key prefix (includes TEST_TOKEN if available).
     */
    protected string $redisTestPrefix;

    /**
     * Computed Redis database number.
     */
    protected int $redisTestDatabase;

    /**
     * The Redis connection name to configure.
     */
    protected string $redisTestConnection = 'default';

    /**
     * Compute parallel-safe Redis configuration based on TEST_TOKEN.
     *
     * TEST_TOKEN is provided by paratest and is unique per worker (1, 2, 3, etc.).
     * This method should be called early in setUp(), before parent::setUp().
     */
    protected function computeRedisTestConfig(): void
    {
        $testToken = env('TEST_TOKEN', '');

        if ($testToken !== '') {
            // Parallel execution: use unique prefix and database per worker
            $this->redisTestPrefix = "{$this->redisTestBasePrefix}_{$testToken}:";

            // Use databases 8-15 for parallel workers (leaving 0-7 for other uses)
            // Use abs() to handle any negative values safely
            $workerNum = is_numeric($testToken) ? (int) $testToken : crc32($testToken);
            $this->redisTestDatabase = $this->redisTestBaseDatabase + (abs($workerNum) % 8);
        } else {
            // Sequential execution: use base config
            $this->redisTestPrefix = "{$this->redisTestBasePrefix}:";
            $this->redisTestDatabase = $this->redisTestBaseDatabase;
        }
    }

    /**
     * Configure Redis connection settings for testing.
     *
     * This method should be called after parent::setUp() when $this->app is available.
     * It configures the Redis connection with test-specific settings from environment
     * variables and applies the parallel-safe database number and prefix.
     */
    protected function configureRedisConnection(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $config->set("database.redis.{$this->redisTestConnection}", [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'auth' => env('REDIS_AUTH', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => $this->redisTestDatabase,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ]);

        $config->set('database.redis.options.prefix', $this->redisTestPrefix);
    }

    /**
     * Flush only Redis keys matching the test prefix.
     *
     * This is safer than FLUSHDB for parallel execution as it only
     * removes keys belonging to this specific test worker.
     *
     * Uses SCAN-based deletion which:
     * - Handles OPT_PREFIX correctly (avoiding double-prefix bugs)
     * - Works with large key sets (batched deletion)
     * - Is non-blocking (uses SCAN instead of KEYS)
     */
    protected function flushRedisTestKeys(): void
    {
        try {
            // Since $this->redisTestPrefix IS the OPT_PREFIX, passing '*' matches
            // all keys under that prefix. flushByPattern handles OPT_PREFIX internally.
            Redis::connection($this->redisTestConnection)->flushByPattern('*');
        } catch (Throwable) {
            // Ignore errors during cleanup - Redis may not be available
        }
    }

    /**
     * Get the configured Redis test prefix.
     */
    protected function getRedisTestPrefix(): string
    {
        return $this->redisTestPrefix;
    }

    /**
     * Get the configured Redis test database number.
     */
    protected function getRedisTestDatabase(): int
    {
        return $this->redisTestDatabase;
    }
}
