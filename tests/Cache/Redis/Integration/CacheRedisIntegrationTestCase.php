<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\Contracts\Repository;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Support\RedisIntegrationTestCase;
use Redis as PhpRedis;

/**
 * Base test case for Cache + Redis integration tests.
 *
 * Extends the generic Redis integration test case and adds
 * cache-specific configuration (sets Redis as the cache driver).
 *
 * Provides helper methods for:
 * - Switching between tag modes (all/any)
 * - Accessing raw Redis client for verification
 * - Computing tag hash keys for each mode
 * - Common assertions for tag structures
 *
 * NOTE: Concrete test classes extending this MUST add @group redis-integration
 * for proper test filtering in CI.
 *
 * @internal
 * @coversNothing
 */
abstract class CacheRedisIntegrationTestCase extends RedisIntegrationTestCase
{
    /**
     * Configure cache to use Redis as the default driver.
     */
    protected function configurePackage(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $config->set('cache.default', 'redis');
    }

    /**
     * Get the cache repository.
     */
    protected function cache(): Repository
    {
        return Cache::store('redis');
    }

    /**
     * Get the underlying RedisStore.
     */
    protected function store(): RedisStore
    {
        $store = $this->cache()->getStore();
        assert($store instanceof RedisStore);

        return $store;
    }

    /**
     * Get a raw phpredis client for direct Redis verification.
     *
     * Note: This client has OPT_PREFIX set to testPrefix, so keys
     * are automatically prefixed when using this client.
     */
    protected function redis(): PhpRedis
    {
        return Redis::client();
    }

    /**
     * Set the tag mode on the store.
     */
    protected function setTagMode(TagMode|string $mode): void
    {
        $this->store()->setTagMode($mode);
    }

    /**
     * Get the current tag mode.
     */
    protected function getTagMode(): TagMode
    {
        return $this->store()->getTagMode();
    }

    /**
     * Get the cache prefix (includes test prefix from parent).
     */
    protected function getCachePrefix(): string
    {
        return $this->store()->getPrefix();
    }

    // =========================================================================
    // ALL MODE HELPERS
    // =========================================================================

    /**
     * Get the tag ZSET key for all mode.
     * Format: {prefix}_all:tag:{name}:entries
     */
    protected function allModeTagKey(string $tagName): string
    {
        return $this->getCachePrefix() . '_all:tag:' . $tagName . ':entries';
    }

    /**
     * Get all entries from an all-mode tag ZSET.
     *
     * @return array<string, float> Key => score mapping
     */
    protected function getAllModeTagEntries(string $tagName): array
    {
        $key = $this->allModeTagKey($tagName);
        $result = $this->redis()->zRange($key, 0, -1, ['WITHSCORES' => true]);

        return is_array($result) ? $result : [];
    }

    /**
     * Check if an entry exists in all-mode tag ZSET.
     */
    protected function allModeTagHasEntry(string $tagName, string $cacheKey): bool
    {
        $key = $this->allModeTagKey($tagName);

        return $this->redis()->zScore($key, $cacheKey) !== false;
    }

    // =========================================================================
    // ANY MODE HELPERS
    // =========================================================================

    /**
     * Get the tag HASH key for any mode.
     * Format: {prefix}_any:tag:{name}:entries
     */
    protected function anyModeTagKey(string $tagName): string
    {
        return $this->getCachePrefix() . '_any:tag:' . $tagName . ':entries';
    }

    /**
     * Get the reverse index SET key for any mode.
     * Format: {prefix}{cacheKey}:_any:tags
     */
    protected function anyModeReverseIndexKey(string $cacheKey): string
    {
        return $this->getCachePrefix() . $cacheKey . ':_any:tags';
    }

    /**
     * Get the tag registry ZSET key for any mode.
     * Format: {prefix}_any:tag:registry
     */
    protected function anyModeRegistryKey(): string
    {
        return $this->getCachePrefix() . '_any:tag:registry';
    }

    /**
     * Get all fields from an any-mode tag HASH.
     *
     * @return array<string, string> Field => value mapping
     */
    protected function getAnyModeTagEntries(string $tagName): array
    {
        $key = $this->anyModeTagKey($tagName);
        $result = $this->redis()->hGetAll($key);

        return is_array($result) ? $result : [];
    }

    /**
     * Check if a field exists in any-mode tag HASH.
     */
    protected function anyModeTagHasEntry(string $tagName, string $cacheKey): bool
    {
        $key = $this->anyModeTagKey($tagName);

        return $this->redis()->hExists($key, $cacheKey);
    }

    /**
     * Get tags from reverse index SET for any mode.
     *
     * @return array<string>
     */
    protected function getAnyModeReverseIndex(string $cacheKey): array
    {
        $key = $this->anyModeReverseIndexKey($cacheKey);
        $result = $this->redis()->sMembers($key);

        return is_array($result) ? $result : [];
    }

    /**
     * Get all tags from registry ZSET for any mode.
     *
     * @return array<string, float> Tag name => score mapping
     */
    protected function getAnyModeRegistry(): array
    {
        $key = $this->anyModeRegistryKey();
        $result = $this->redis()->zRange($key, 0, -1, ['WITHSCORES' => true]);

        return is_array($result) ? $result : [];
    }

    /**
     * Check if a tag exists in the any-mode registry.
     */
    protected function anyModeRegistryHasTag(string $tagName): bool
    {
        $key = $this->anyModeRegistryKey();

        return $this->redis()->zScore($key, $tagName) !== false;
    }

    // =========================================================================
    // GENERIC HELPERS
    // =========================================================================

    /**
     * Get the tag key based on current mode.
     */
    protected function tagKey(string $tagName): string
    {
        return $this->getTagMode()->isAnyMode()
            ? $this->anyModeTagKey($tagName)
            : $this->allModeTagKey($tagName);
    }

    /**
     * Check if a cache key exists in the tag structure for current mode.
     */
    protected function tagHasEntry(string $tagName, string $cacheKey): bool
    {
        return $this->getTagMode()->isAnyMode()
            ? $this->anyModeTagHasEntry($tagName, $cacheKey)
            : $this->allModeTagHasEntry($tagName, $cacheKey);
    }

    /**
     * Run a test callback for both tag modes.
     *
     * This is useful for tests that should verify behavior in both modes.
     * The callback receives the current TagMode being tested.
     *
     * @param callable(TagMode): void $callback
     */
    protected function forBothModes(callable $callback): void
    {
        foreach ([TagMode::All, TagMode::Any] as $mode) {
            $this->setTagMode($mode);

            // Flush to clean state between modes
            Redis::flushByPattern('*');

            $callback($mode);
        }
    }

    /**
     * Assert that a Redis key exists.
     */
    protected function assertRedisKeyExists(string $key, string $message = ''): void
    {
        $this->assertTrue(
            $this->redis()->exists($key) > 0,
            $message ?: "Redis key '{$key}' should exist"
        );
    }

    /**
     * Assert that a Redis key does not exist.
     */
    protected function assertRedisKeyNotExists(string $key, string $message = ''): void
    {
        $this->assertFalse(
            $this->redis()->exists($key) > 0,
            $message ?: "Redis key '{$key}' should not exist"
        );
    }

    /**
     * Assert that a cache key is tracked in its tag structure.
     */
    protected function assertKeyTrackedInTag(string $tagName, string $cacheKey, string $message = ''): void
    {
        $this->assertTrue(
            $this->tagHasEntry($tagName, $cacheKey),
            $message ?: "Cache key '{$cacheKey}' should be tracked in tag '{$tagName}'"
        );
    }

    /**
     * Assert that a cache key is NOT tracked in its tag structure.
     */
    protected function assertKeyNotTrackedInTag(string $tagName, string $cacheKey, string $message = ''): void
    {
        $this->assertFalse(
            $this->tagHasEntry($tagName, $cacheKey),
            $message ?: "Cache key '{$cacheKey}' should not be tracked in tag '{$tagName}'"
        );
    }

    // =========================================================================
    // CUSTOM CONNECTION HELPERS
    // =========================================================================

    /**
     * Track custom connections created during tests for cleanup.
     *
     * @var array<string>
     */
    private array $customConnections = [];

    /**
     * Create a Redis connection with a specific OPT_PREFIX.
     *
     * This allows testing different prefix configurations:
     * - Empty string for no OPT_PREFIX
     * - Custom string for specific OPT_PREFIX
     *
     * The connection is registered in config and can be used to create stores.
     *
     * @param string $optPrefix The OPT_PREFIX to set (empty string for none)
     * @return string The connection name to use with RedisStore
     */
    protected function createConnectionWithOptPrefix(string $optPrefix): string
    {
        $connectionName = 'test_opt_' . ($optPrefix === '' ? 'none' : md5($optPrefix));

        // Don't recreate if already exists
        if (in_array($connectionName, $this->customConnections, true)) {
            return $connectionName;
        }

        $config = $this->app->get(ConfigInterface::class);

        // Build connection config with correct test database
        // Note: We can't rely on redis.default because FoundationServiceProvider
        // copies database.redis.* to redis.* at boot (before test's setUp runs)
        $connectionConfig = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'auth' => env('REDIS_AUTH', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => (int) env('REDIS_DB', $this->redisDatabase),
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

        // Register the new connection directly to redis.* (runtime config location)
        $config->set("redis.{$connectionName}", $connectionConfig);

        $this->customConnections[] = $connectionName;

        return $connectionName;
    }

    /**
     * Get a raw phpredis client without any OPT_PREFIX.
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

        $client->select((int) env('REDIS_DB', $this->redisDatabase));

        return $client;
    }

    /**
     * Clean up keys matching a pattern using raw client.
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
}
