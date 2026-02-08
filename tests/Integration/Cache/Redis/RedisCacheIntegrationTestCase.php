<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache\Redis;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Redis as PhpRedis;

/**
 * Base test case for Redis Cache integration tests.
 *
 * Uses InteractsWithRedis trait which auto-handles:
 * - Auto-skip if Redis unavailable
 * - Database flushing in setUp/tearDown
 *
 * Provides helper methods for:
 * - Switching between tag modes (all/any) with auto-skip for unsupported environments
 * - Accessing raw Redis client for verification
 * - Computing tag hash keys for each mode
 * - Common assertions for tag structures
 *
 * @internal
 * @coversNothing
 */
abstract class RedisCacheIntegrationTestCase extends TestCase
{
    use InteractsWithRedis;
    use RunTestsInCoroutine;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->get(Repository::class);

        // Configure Redis (prefix comes from REDIS_PREFIX env var set by bootstrap)
        $this->configureRedisForTesting($config);

        // Set Redis as cache driver
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
     * Minimum phpredis version required for any tag mode.
     */
    private const PHPREDIS_MIN_VERSION = '6.3.0';

    /**
     * Minimum Redis version required for any tag mode.
     */
    private const REDIS_MIN_VERSION = '8.0.0';

    /**
     * Minimum Valkey version required for any tag mode.
     */
    private const VALKEY_MIN_VERSION = '9.0.0';

    /**
     * Cached result of any tag mode support check (null = not checked yet).
     */
    private static ?bool $anyTagModeSupported = null;

    /**
     * Cached skip reason when any tag mode is not supported.
     */
    private static string $anyTagModeSkipReason = '';

    /**
     * Set the tag mode on the store.
     *
     * When setting to Any mode, checks version requirements and skips
     * the test if phpredis < 6.3.0 or Redis < 8.0 / Valkey < 9.0.
     */
    protected function setTagMode(TagMode|string $mode): void
    {
        $mode = $mode instanceof TagMode ? $mode : TagMode::from($mode);

        if ($mode === TagMode::Any) {
            $this->skipIfAnyTagModeUnsupported();
        }

        $this->store()->setTagMode($mode);
    }

    /**
     * Skip the test if any tag mode requirements are not met.
     *
     * Any tag mode requires:
     * - phpredis >= 6.3.0 (for HSETEX support)
     * - Redis >= 8.0.0 OR Valkey >= 9.0.0 (for HEXPIRE command)
     *
     * The check is performed once per process and cached for performance.
     */
    protected function skipIfAnyTagModeUnsupported(): void
    {
        if (self::$anyTagModeSupported === null) {
            self::$anyTagModeSupported = $this->checkAnyTagModeSupport();
        }

        if (! self::$anyTagModeSupported) {
            $this->markTestSkipped(self::$anyTagModeSkipReason);
        }
    }

    /**
     * Check if the environment supports any tag mode.
     */
    private function checkAnyTagModeSupport(): bool
    {
        // Check phpredis version
        $phpredisVersion = phpversion('redis') ?: '0';
        if (version_compare($phpredisVersion, self::PHPREDIS_MIN_VERSION, '<')) {
            self::$anyTagModeSkipReason = 'Any tag mode requires phpredis >= ' . self::PHPREDIS_MIN_VERSION . " (installed: {$phpredisVersion})";

            return false;
        }

        // Check Redis/Valkey version
        $info = $this->redis()->info('server');

        if (isset($info['valkey_version'])) {
            if (version_compare($info['valkey_version'], self::VALKEY_MIN_VERSION, '<')) {
                self::$anyTagModeSkipReason = 'Any tag mode requires Valkey >= ' . self::VALKEY_MIN_VERSION . " (installed: {$info['valkey_version']})";

                return false;
            }
        } elseif (isset($info['redis_version'])) {
            if (version_compare($info['redis_version'], self::REDIS_MIN_VERSION, '<')) {
                self::$anyTagModeSkipReason = 'Any tag mode requires Redis >= ' . self::REDIS_MIN_VERSION . " (installed: {$info['redis_version']})";

                return false;
            }
        }

        return true;
    }

    /**
     * Get the current tag mode.
     */
    protected function getTagMode(): TagMode
    {
        return $this->store()->getTagMode();
    }

    /**
     * Get the cache prefix (includes test prefix).
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
     * Format: {prefix}_all:tag:{name}:entries.
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
     * Format: {prefix}_any:tag:{name}:entries.
     */
    protected function anyModeTagKey(string $tagName): string
    {
        return $this->getCachePrefix() . '_any:tag:' . $tagName . ':entries';
    }

    /**
     * Get the reverse index SET key for any mode.
     * Format: {prefix}{cacheKey}:_any:tags.
     */
    protected function anyModeReverseIndexKey(string $cacheKey): string
    {
        return $this->getCachePrefix() . $cacheKey . ':_any:tags';
    }

    /**
     * Get the tag registry ZSET key for any mode.
     * Format: {prefix}_any:tag:registry.
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
}
