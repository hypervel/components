<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache with all tag tracking.
 *
 * Combines the ZADD operations for tag tracking with the SETEX for
 * cache storage in a single connection checkout for efficiency.
 *
 * Each tag maintains a sorted set where:
 * - Members are cache keys (namespaced)
 * - Scores are TTL timestamps (when the entry expires)
 */
class Put
{
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {}

    /**
     * Execute the put operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param mixed $value The value to store
     * @param int $seconds TTL in seconds
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @return bool True if successful
     */
    public function execute(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $seconds, $tagIds);
        }

        return $this->executePipeline($key, $value, $seconds, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * Pipelines ZADD commands for all tags + SETEX in a single round trip.
     */
    private function executePipeline(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $score = now()->addSeconds($seconds)->getTimestamp();
            $serialized = $this->serialization->serialize($conn, $value);

            $pipeline = $client->pipeline();

            // ZADD to each tag's sorted set
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, $score, $key);
            }

            // SETEX for the cache value
            $pipeline->setex($prefix . $key, max(1, $seconds), $serialized);

            $results = $pipeline->exec();

            // Last result is the SETEX - check it succeeded
            return $results !== false && end($results) !== false;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Each tag sorted set may be in a different slot, so we must
     * execute commands sequentially rather than in a pipeline.
     */
    private function executeCluster(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $score = now()->addSeconds($seconds)->getTimestamp();
            $serialized = $this->serialization->serialize($conn, $value);

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $client->zadd($prefix . $tagId, $score, $key);
            }

            // SETEX for the cache value
            return (bool) $client->setex($prefix . $key, max(1, $seconds), $serialized);
        });
    }
}
