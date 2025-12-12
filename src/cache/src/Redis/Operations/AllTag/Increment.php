<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Increment a value in the cache with all tag tracking.
 *
 * Combines the ZADD NX operations for tag tracking with INCRBY
 * in a single connection checkout for efficiency.
 *
 * Uses ZADD NX (only add if not exists) to avoid overwriting existing
 * tag entries that may have TTL information.
 */
class Increment
{
    /**
     * Score for increment operations (no TTL - persists until deleted).
     */
    private const FOREVER_SCORE = -1;

    public function __construct(
        private readonly StoreContext $context,
    ) {}

    /**
     * Execute the increment operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param int $value The value to increment by
     * @param array<string> $tagIds Array of tag identifiers
     * @return int|false The new value after incrementing, or false on failure
     */
    public function execute(string $key, int $value, array $tagIds): int|false
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $tagIds);
        }

        return $this->executePipeline($key, $value, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     */
    private function executePipeline(string $key, int $value, array $tagIds): int|false
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();

            $pipeline = $client->pipeline();

            // ZADD NX to each tag's sorted set (only add if not exists)
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, ['NX'], self::FOREVER_SCORE, $key);
            }

            // INCRBY for the value
            $pipeline->incrby($prefix . $key, $value);

            $results = $pipeline->exec();

            if ($results === false) {
                return false;
            }

            // Last result is the INCRBY result
            return end($results);
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     */
    private function executeCluster(string $key, int $value, array $tagIds): int|false
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();

            // ZADD NX to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $client->zadd($prefix . $tagId, ['NX'], self::FOREVER_SCORE, $key);
            }

            // INCRBY for the value
            return $client->incrby($prefix . $key, $value);
        });
    }
}
