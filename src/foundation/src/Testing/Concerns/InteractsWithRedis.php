<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Support\Facades\Redis;
use Throwable;

/**
 * Provides Redis integration testing support.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithRedis() runs after app boots
 * - tearDownInteractsWithRedis() runs via beforeApplicationDestroyed()
 *
 * Tests that need Redis config overrides (prefix, DB number) should set
 * them in defineEnvironment() via $app->make('config')->set(...).
 *
 * Environment Variables:
 * - REDIS_HOST: Redis host (default: 127.0.0.1)
 * - REDIS_PORT: Redis port (default: 6379)
 * - REDIS_DB: Redis database number (default: 8)
 * - REDIS_AUTH: Redis password (optional)
 */
trait InteractsWithRedis
{
    /**
     * Indicates if connection failed once with defaults, skip all subsequent tests.
     */
    private static bool $connectionFailedOnceWithDefaultsSkip = false;

    /**
     * Set up Redis for testing (auto-called by setUpTraits).
     *
     * Follows Laravel's InteractsWithRedis pattern:
     * - Only skips if using default host/port AND no explicit REDIS_HOST env var
     * - If explicit config exists and fails, the exception propagates (misconfiguration)
     */
    protected function setUpInteractsWithRedis(): void
    {
        if (static::$connectionFailedOnceWithDefaultsSkip) {
            $this->markTestSkipped(
                'Redis connection failed with defaults. Set REDIS_HOST & REDIS_PORT to enable ' . static::class
            );
        }

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        try {
            $this->flushRedis();
        } catch (Throwable $e) {
            if ($host === '127.0.0.1' && $port === 6379 && env('REDIS_HOST') === null) {
                static::$connectionFailedOnceWithDefaultsSkip = true;
                $this->markTestSkipped(
                    'Redis connection failed with defaults. Set REDIS_HOST & REDIS_PORT to enable ' . static::class
                );
            }
            // Explicit config exists but failed - rethrow so test fails (misconfiguration)
            throw $e;
        }
    }

    /**
     * Tear down Redis (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithRedis(): void
    {
        if (static::$connectionFailedOnceWithDefaultsSkip) {
            return;
        }

        try {
            $this->flushRedis();
        } catch (Throwable) {
            // Ignore cleanup errors
        }
    }

    /**
     * Flush the Redis database.
     */
    protected function flushRedis(): void
    {
        Redis::flushdb();
    }

    /**
     * Check if REDIS_HOST was explicitly set in environment.
     */
    protected function hasExplicitRedisConfig(): bool
    {
        return env('REDIS_HOST') !== null;
    }

    /**
     * Get a raw phpredis client for direct Redis operations.
     *
     * This client has OPT_PREFIX set to the test prefix, so keys
     * are automatically prefixed when using this client.
     */
    protected function redisClient(): \Redis
    {
        return Redis::client();
    }

    /**
     * Get a raw phpredis client WITHOUT any OPT_PREFIX.
     *
     * Useful for verifying actual key names in Redis.
     */
    protected function rawRedisClientWithoutPrefix(): \Redis
    {
        $client = new \Redis();
        $client->connect(
            env('REDIS_HOST', '127.0.0.1'),
            (int) env('REDIS_PORT', 6379)
        );

        $auth = env('REDIS_AUTH');
        if ($auth) {
            $client->auth($auth);
        }

        $client->select((int) env('REDIS_DB', 8));

        return $client;
    }

    /**
     * Clean up keys matching a pattern using raw client (no prefix).
     */
    protected function cleanupKeysWithPattern(string $pattern): void
    {
        $client = $this->rawRedisClientWithoutPrefix();
        $keys = $client->keys($pattern);
        if (! empty($keys)) {
            $client->del(...$keys);
        }
        $client->close();
    }

    /**
     * Create a named Redis connection with a specific OPT_PREFIX for testing.
     *
     * Use this when a test needs multiple connections with different prefixes.
     * For a single no-prefix connection, just set the prefix on the default
     * connection in defineEnvironment() instead.
     */
    protected function createRedisConnectionWithPrefix(string $optPrefix): string
    {
        $connectionName = 'test_opt_' . ($optPrefix === '' ? 'none' : md5($optPrefix));

        $config = $this->app->make('config');

        // Check if already exists
        if ($config->get("database.redis.{$connectionName}") !== null) {
            return $connectionName;
        }

        $connectionConfig = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'auth' => env('REDIS_AUTH', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => (int) env('REDIS_DB', 8),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
            'options' => [
                'prefix' => $optPrefix,
            ],
        ];

        $config->set("database.redis.{$connectionName}", $connectionConfig);

        // RedisFactory snapshots configured pools in __construct, so reset it after adding runtime test pools.
        $this->app->forgetInstance(\Hypervel\Redis\RedisFactory::class);

        return $connectionName;
    }
}
