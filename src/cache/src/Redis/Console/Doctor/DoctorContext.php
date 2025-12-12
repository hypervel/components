<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\Repository;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;

/**
 * Context object holding shared state for Doctor checks.
 *
 * Bundles all dependencies needed by functional checks to avoid
 * passing many parameters to each check's run() method.
 */
final class DoctorContext
{
    /**
     * Unique prefix to prevent collision with production data.
     * Mode-agnostic - just identifies doctor test data.
     */
    private const TEST_PREFIX = '_doctor:test:';

    /**
     * Create a new doctor context instance.
     */
    public function __construct(
        public readonly Repository $cache,
        public readonly RedisStore $store,
        public readonly RedisConnection $redis,
        public readonly string $cachePrefix,
        public readonly string $storeName,
    ) {}

    /**
     * Get a value prefixed with the unique doctor test prefix.
     * Used for both cache keys and tag names to ensure complete isolation from production data.
     */
    public function prefixed(string $value): string
    {
        return self::TEST_PREFIX . $value;
    }

    /**
     * Get the full Redis key for a tag hash/ZSET.
     *
     * Format: "{cachePrefix}_any:tag:{tagName}:entries" or "{cachePrefix}_all:tag:{tagName}:entries"
     */
    public function tagHashKey(string $tag): string
    {
        return $this->store->getContext()->tagHashKey($tag);
    }

    /**
     * Get the tag identifier (without cache prefix).
     *
     * Format: "_any:tag:{tagName}:entries" or "_all:tag:{tagName}:entries"
     * Used for namespace computation in all mode.
     */
    public function tagId(string $tag): string
    {
        return $this->store->getContext()->tagId($tag);
    }

    /**
     * Compute the namespaced key for a tagged cache item in all mode.
     *
     * In all mode, cache keys are prefixed with sha1 of sorted tag IDs.
     * Format: "{sha1}:{key}"
     *
     * @param array<string> $tags The tag names
     * @param string $key The cache key
     * @return string The namespaced key
     */
    public function namespacedKey(array $tags, string $key): string
    {
        $tagIds = array_map(fn (string $tag) => $this->tagId($tag), $tags);
        sort($tagIds);
        $namespace = sha1(implode('|', $tagIds));

        return $namespace . ':' . $key;
    }

    /**
     * Get the test prefix constant for cleanup operations.
     */
    public function getTestPrefix(): string
    {
        return self::TEST_PREFIX;
    }

    /**
     * Check if the store is configured for 'any' tag mode.
     * In this mode, flushing ANY matching tag removes the item.
     */
    public function isAnyMode(): bool
    {
        return $this->store->getTagMode() === TagMode::Any;
    }

    /**
     * Check if the store is configured for 'all' tag mode.
     * In this mode, items must match ALL specified tags.
     */
    public function isAllMode(): bool
    {
        return $this->store->getTagMode() === TagMode::All;
    }

    /**
     * Get the current tag mode.
     */
    public function getTagMode(): TagMode
    {
        return $this->store->getTagMode();
    }

    /**
     * Get the current tag mode as a string value.
     */
    public function getTagModeValue(): string
    {
        return $this->store->getTagMode()->value;
    }

    /**
     * Get patterns to match all tag storage structures with a given tag name prefix.
     *
     * Used for cleanup operations to delete dynamically-created test tags.
     * Returns patterns for BOTH tag modes to ensure complete cleanup
     * regardless of current mode (e.g., if config changed between runs):
     * - Any mode: {cachePrefix}_any:tag:{tagNamePrefix}*
     * - All mode: {cachePrefix}_all:tag:{tagNamePrefix}*
     *
     * @param string $tagNamePrefix The prefix to match tag names against
     * @return array<string> Patterns to use with SCAN/KEYS commands
     */
    public function getTagStoragePatterns(string $tagNamePrefix): array
    {
        return [
            // Any mode tag storage: {cachePrefix}_any:tag:{tagNamePrefix}*
            $this->cachePrefix . TagMode::Any->tagSegment() . $tagNamePrefix . '*',
            // All mode tag storage: {cachePrefix}_all:tag:{tagNamePrefix}*
            $this->cachePrefix . TagMode::All->tagSegment() . $tagNamePrefix . '*',
        ];
    }

    /**
     * Get patterns to match all cache value keys with a given key prefix.
     *
     * Used for cleanup operations to delete test cache values.
     * Returns patterns for BOTH tag modes to ensure complete cleanup
     * regardless of current mode (e.g., if config changed between runs):
     * - Untagged keys: {cachePrefix}{keyPrefix}* (same in both modes)
     * - Tagged keys in all mode: {cachePrefix}{sha1}:{keyPrefix}* (namespaced)
     *
     * @param string $keyPrefix The prefix to match cache keys against
     * @return array<string> Patterns to use with SCAN/KEYS commands
     */
    public function getCacheValuePatterns(string $keyPrefix): array
    {
        return [
            // Untagged cache values (both modes) and any-mode tagged values
            $this->cachePrefix . $keyPrefix . '*',
            // All-mode tagged values at {cachePrefix}{sha1}:{keyName}
            $this->cachePrefix . '*:' . $keyPrefix . '*',
        ];
    }
}
