<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Closure;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Get an item from the cache, or execute a callback and store the result with tag tracking.
 *
 * This operation is optimized to use a single connection for both the GET
 * and the tagged PUT operations, avoiding the overhead of acquiring/releasing
 * a connection from the pool multiple times for cache misses.
 *
 * On cache miss, creates:
 * 1. ZADD entries in each tag's sorted set (score = TTL timestamp)
 * 2. The cache value with TTL (SETEX)
 */
class Remember
{
    /**
     * Create a new remember operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the remember operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param int $seconds TTL in seconds
     * @param Closure $callback The callback to execute on cache miss
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    public function execute(string $key, int $seconds, Closure $callback, array $tagIds): array
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $seconds, $callback, $tagIds);
        }

        return $this->executePipeline($key, $seconds, $callback, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * GET first, then on miss: pipelines ZADD commands for all tags + SETEX in a single round trip.
     *
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    private function executePipeline(string $key, int $seconds, Closure $callback, array $tagIds): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds, $callback, $tagIds) {
            $prefix = $this->context->prefix();
            $prefixedKey = $prefix . $key;

            // Try to get the cached value
            $value = $connection->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($connection, $value), true];
            }

            // Cache miss - execute callback
            $value = $callback();

            // Now store with tag tracking using pipeline
            $score = now()->addSeconds($seconds)->getTimestamp();
            $serialized = $this->serialization->serialize($connection, $value);

            $pipeline = $connection->pipeline();

            // ZADD to each tag's sorted set
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, $score, $key);
            }

            // SETEX for the cache value
            $pipeline->setex($prefixedKey, max(1, $seconds), $serialized);

            $pipeline->exec();

            return [$value, false];
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Each tag sorted set may be in a different slot, so we must
     * execute commands sequentially rather than in a pipeline.
     *
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    private function executeCluster(string $key, int $seconds, Closure $callback, array $tagIds): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds, $callback, $tagIds) {
            $prefix = $this->context->prefix();
            $prefixedKey = $prefix . $key;

            // Try to get the cached value
            $value = $connection->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($connection, $value), true];
            }

            // Cache miss - execute callback
            $value = $callback();

            // Now store with tag tracking using sequential commands
            $score = now()->addSeconds($seconds)->getTimestamp();
            $serialized = $this->serialization->serialize($connection, $value);

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $connection->zadd($prefix . $tagId, $score, $key);
            }

            // SETEX for the cache value
            $connection->setex($prefixedKey, max(1, $seconds), $serialized);

            return [$value, false];
        });
    }
}
