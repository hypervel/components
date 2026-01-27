<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Support;

use Hypervel\Cache\Redis\TagMode;
use Hypervel\Context\ApplicationContext;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisFactory;
use Redis;

/**
 * Mode-aware context for Redis cache operations.
 *
 * This class encapsulates the dependencies that all cache operations need,
 * providing a clean interface to Redis connection, client, and configuration.
 * It receives TagMode via dependency injection and delegates all key-building
 * to the TagMode enum (single source of truth for mode-specific patterns).
 */
class StoreContext
{
    /**
     * The maximum expiry timestamp (Year 9999) for "forever" items.
     * Used in the tag registry to represent items with no expiration.
     */
    public const MAX_EXPIRY = 253402300799;

    /**
     * The value stored in tag hash fields.
     * We only need to track membership, so we use a minimal placeholder value.
     */
    public const TAG_FIELD_VALUE = '1';

    public function __construct(
        private readonly string $connectionName,
        private readonly string $prefix,
        private readonly TagMode $tagMode,
    ) {
    }

    /**
     * Get the cache key prefix.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the connection name.
     */
    public function connectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get the tag mode.
     */
    public function tagMode(): TagMode
    {
        return $this->tagMode;
    }

    /**
     * Get the tag identifier (without cache prefix).
     *
     * Used by All mode for namespace computation (sha1 of sorted tag IDs).
     * Format: "_any:tag:{tagName}:entries" or "_all:tag:{tagName}:entries"
     */
    public function tagId(string $tag): string
    {
        return $this->tagMode->tagId($tag);
    }

    /**
     * Get the full tag hash key for a given tag.
     *
     * Format: "{prefix}_any:tag:{tagName}:entries" or "{prefix}_all:tag:{tagName}:entries"
     */
    public function tagHashKey(string $tag): string
    {
        return $this->tagMode->tagKey($this->prefix, $tag);
    }

    /**
     * Get the tag hash suffix (for Lua scripts that build keys dynamically).
     */
    public function tagHashSuffix(): string
    {
        return ':entries';
    }

    /**
     * Get the SCAN pattern for finding all tag sorted sets.
     *
     * Format: "{prefix}_any:tag:*:entries" or "{prefix}_all:tag:*:entries"
     */
    public function tagScanPattern(): string
    {
        return $this->prefix . $this->tagMode->tagSegment() . '*:entries';
    }

    /**
     * Get the full reverse index key for a cache key.
     *
     * Format: "{prefix}{cacheKey}:_any:tags" or "{prefix}{cacheKey}:_all:tags"
     */
    public function reverseIndexKey(string $key): string
    {
        return $this->tagMode->reverseIndexKey($this->prefix, $key);
    }

    /**
     * Get the tag registry key (without OPT_PREFIX).
     *
     * Format: "{prefix}_any:tag:registry" or "{prefix}_all:tag:registry"
     */
    public function registryKey(): string
    {
        return $this->tagMode->registryKey($this->prefix);
    }

    /**
     * Execute callback with a held connection from the pool.
     *
     * Delegates to Redis::withConnection() for context awareness (respects
     * active pipeline/multi connections). Uses transform: false to provide
     * raw phpredis behavior for cache operations.
     *
     * @template T
     * @param callable(RedisConnection): T $callback
     * @return T
     */
    public function withConnection(callable $callback): mixed
    {
        return ApplicationContext::getContainer()
            ->get(RedisFactory::class)
            ->get($this->connectionName)
            ->withConnection($callback, transform: false);
    }

    /**
     * Check if the connection is a Redis Cluster.
     */
    public function isCluster(): bool
    {
        return $this->withConnection(
            fn (RedisConnection $connection) => $connection->isCluster()
        );
    }

    /**
     * Get the OPT_PREFIX value from the Redis client.
     */
    public function optPrefix(): string
    {
        return $this->withConnection(
            fn (RedisConnection $connection) => (string) $connection->getOption(Redis::OPT_PREFIX)
        );
    }

    /**
     * Get the full tag prefix including OPT_PREFIX (for Lua scripts).
     *
     * Format: "{optPrefix}{prefix}_any:tag:" or "{optPrefix}{prefix}_all:tag:"
     */
    public function fullTagPrefix(): string
    {
        return $this->optPrefix() . $this->prefix . $this->tagMode->tagSegment();
    }

    /**
     * Get the full reverse index key including OPT_PREFIX (for Lua scripts).
     */
    public function fullReverseIndexKey(string $key): string
    {
        return $this->optPrefix() . $this->tagMode->reverseIndexKey($this->prefix, $key);
    }

    /**
     * Get the full registry key including OPT_PREFIX (for Lua scripts).
     */
    public function fullRegistryKey(): string
    {
        return $this->optPrefix() . $this->tagMode->registryKey($this->prefix);
    }
}
