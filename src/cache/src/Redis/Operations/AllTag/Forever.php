<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache indefinitely with all tag tracking.
 *
 * Combines the ZADD operations for tag tracking with the SET for
 * cache storage in a single connection checkout for efficiency.
 *
 * Forever items use a score of -1 in the tag sorted sets, which
 * prevents them from being cleaned by ZREMRANGEBYSCORE operations.
 */
class Forever
{
    private const FOREVER_SCORE = -1;

    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the forever operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param mixed $value The value to store
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @return bool True if successful
     */
    public function execute(string $key, mixed $value, array $tagIds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $tagIds);
        }

        return $this->executePipeline($key, $value, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     */
    private function executePipeline(string $key, mixed $value, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $serialized = $this->serialization->serialize($conn, $value);

            $pipeline = $client->pipeline();

            // ZADD to each tag's sorted set with score -1 (forever)
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, self::FOREVER_SCORE, $key);
            }

            // SET for the cache value (no expiration)
            $pipeline->set($prefix . $key, $serialized);

            $results = $pipeline->exec();

            // Last result is the SET - check it succeeded
            return $results !== false && end($results) !== false;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     */
    private function executeCluster(string $key, mixed $value, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $serialized = $this->serialization->serialize($conn, $value);

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $client->zadd($prefix . $tagId, self::FOREVER_SCORE, $key);
            }

            // SET for the cache value (no expiration)
            return (bool) $client->set($prefix . $key, $serialized);
        });
    }
}
