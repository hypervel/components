<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Closure;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Get an item from the cache, or execute a callback and store the result forever with tag tracking.
 *
 * This operation is optimized to use a single connection for both the GET
 * and the tagged SET operations, avoiding the overhead of acquiring/releasing
 * a connection from the pool multiple times for cache misses.
 *
 * Unlike Remember which uses SETEX with TTL and timestamp scores, this uses:
 * - SET without expiration for the cache value
 * - ZADD with score -1 for tag entries (prevents cleanup by ZREMRANGEBYSCORE)
 */
class RememberForever
{
    private const FOREVER_SCORE = -1;

    /**
     * Create a new remember forever operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the remember forever operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param Closure $callback The callback to execute on cache miss
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    public function execute(string $key, Closure $callback, array $tagIds): array
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $callback, $tagIds);
        }

        return $this->executePipeline($key, $callback, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * GET first, then on miss: pipelines ZADD commands for all tags + SET in a single round trip.
     *
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    private function executePipeline(string $key, Closure $callback, array $tagIds): array
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $callback, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $prefixedKey = $prefix . $key;

            // Try to get the cached value
            $value = $client->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($conn, $value), true];
            }

            // Cache miss - execute callback
            $value = $callback();

            // Now store with tag tracking using pipeline
            $serialized = $this->serialization->serialize($conn, $value);

            $pipeline = $client->pipeline();

            // ZADD to each tag's sorted set with score -1 (forever)
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, self::FOREVER_SCORE, $key);
            }

            // SET for the cache value (no expiration)
            $pipeline->set($prefixedKey, $serialized);

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
    private function executeCluster(string $key, Closure $callback, array $tagIds): array
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $callback, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $prefixedKey = $prefix . $key;

            // Try to get the cached value
            $value = $client->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($conn, $value), true];
            }

            // Cache miss - execute callback
            $value = $callback();

            // Now store with tag tracking using sequential commands
            $serialized = $this->serialization->serialize($conn, $value);

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $client->zadd($prefix . $tagId, self::FOREVER_SCORE, $key);
            }

            // SET for the cache value (no expiration)
            $client->set($prefixedKey, $serialized);

            return [$value, false];
        });
    }
}
