<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Support;

use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use LogicException;
use Redis;
use Throwable;

/**
 * Mode-aware context for Redis cache operations.
 */
class StoreContext
{
    /**
     * The maximum expiry timestamp (Year 9999) for "forever" items.
     * Used in the tag registry to represent items with no expiration.
     */
    public const int MAX_EXPIRY = 253402300799;

    /**
     * The value stored in tag hash fields.
     * We only need to track membership, so we use a minimal placeholder value.
     */
    public const string TAG_FIELD_VALUE = '1';

    private readonly TagKeyBuilder $tagKeyBuilder;

    /**
     * Create a new store context instance.
     */
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connectionName,
        private readonly string $prefix,
        private readonly TagMode $tagMode,
    ) {
        $this->tagKeyBuilder = new TagKeyBuilder($tagMode, $prefix);
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
     * Get the tag membership score for a cache lifetime.
     *
     * When values and tag memberships are written in separate commands, calculate
     * the score after issuing the value writes so a slow batch cannot shorten
     * tag membership.
     */
    public function expirationScore(int $seconds): int
    {
        return CarbonImmutable::now()->addSeconds($seconds)->ceilSecond()->getTimestamp();
    }

    /**
     * Get the tag identifier (without cache prefix).
     *
     * Used by all mode for namespace computation (xxh128 of tag IDs in requested order).
     * Format: "_any:tag:{tagName}:entries" or "_all:tag:{tagName}:entries"
     */
    public function tagId(string $tag): string
    {
        return $this->tagKeyBuilder->tagId($tag);
    }

    /**
     * Get the full tag hash key for a given tag.
     *
     * Format: "{prefix}_any:tag:{tagName}:entries" or "{prefix}_all:tag:{tagName}:entries"
     */
    public function tagHashKey(string $tag): string
    {
        return $this->tagKeyBuilder->tagKey($tag);
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
        return $this->prefix . $this->tagKeyBuilder->tagSegment() . '*:entries';
    }

    /**
     * Get the full reverse index key for a cache key.
     *
     * Format: "{prefix}{cacheKey}:_any:tags" or "{prefix}{cacheKey}:_all:tags"
     */
    public function reverseIndexKey(string $key): string
    {
        return $this->tagKeyBuilder->reverseIndexKey($key);
    }

    /**
     * Get the tag registry key (without OPT_PREFIX).
     *
     * Format: "{prefix}_any:tag:registry" or "{prefix}_all:tag:registry"
     */
    public function registryKey(): string
    {
        return $this->tagKeyBuilder->registryKey();
    }

    /**
     * Execute callback with a held connection from the pool.
     *
     * Delegates to Redis::withConnection(), so a connection held in coroutine context
     * is reused. Cache operations use raw phpredis behavior unless they explicitly
     * request Laravel-style transforms.
     *
     * @template T
     * @param callable(RedisConnection): T $callback
     * @return T
     */
    public function withConnection(callable $callback, bool $transform = false): mixed
    {
        $redis = $this->redis->connection($this->connectionName);

        static::ensureNotQueuing($redis);

        return $redis->withConnection($callback, transform: $transform);
    }

    /**
     * Run write commands in one pipeline.
     *
     * @param callable(Redis): void $commands
     * @return array<int, mixed>|false
     */
    public function pipeline(RedisConnection $connection, callable $commands): array|false
    {
        try {
            $pipeline = $connection->pipeline();
            $commands($pipeline);

            return $pipeline->exec();
        } catch (Throwable $exception) {
            // A failed pipeline can leave queued commands or unread replies on a connection a caller may still hold.
            $connection->invalidate();

            throw $exception;
        }
    }

    /**
     * Ensure a cache operation does not run on a connection queuing a caller's pipeline or transaction.
     *
     * Commands queued there return no replies until the caller's EXEC, so reads, results
     * and lock acquisition would be wrong while their writes still ran later.
     *
     * @internal
     *
     * @throws LogicException
     */
    public static function ensureNotQueuing(RedisProxy $redis): void
    {
        if ($redis->isQueueing()) {
            throw new LogicException(
                'Redis cache operations cannot run while their connection is queuing a pipeline or transaction. Run them before or after it.'
            );
        }
    }

    /**
     * Check if the connection is a Redis Cluster.
     */
    public function isCluster(): bool
    {
        return $this->redis
            ->connection($this->connectionName)
            ->isCluster();
    }

    /**
     * Get the OPT_PREFIX value from the Redis client.
     */
    public function optPrefix(RedisConnection $connection): string
    {
        // Prefix lookups must reuse the operation's connection, not borrow a second one.
        return (string) $connection->getOption(Redis::OPT_PREFIX);
    }

    /**
     * Get the full tag prefix including OPT_PREFIX (for Lua scripts).
     *
     * Format: "{optPrefix}{prefix}_any:tag:" or "{optPrefix}{prefix}_all:tag:"
     */
    public function fullTagPrefix(RedisConnection $connection): string
    {
        return $this->optPrefix($connection) . $this->prefix . $this->tagKeyBuilder->tagSegment();
    }

    /**
     * Get the full registry key including OPT_PREFIX (for Lua scripts).
     */
    public function fullRegistryKey(RedisConnection $connection): string
    {
        return $this->optPrefix($connection) . $this->tagKeyBuilder->registryKey();
    }
}
