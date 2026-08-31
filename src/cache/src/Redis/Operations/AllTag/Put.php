<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache with all tag tracking.
 *
 * Combines SETEX cache storage with the ZADD tag tracking operations in a
 * single connection checkout for efficiency.
 *
 * Each tag maintains a sorted set where:
 * - Members are cache keys (namespaced)
 * - Scores are TTL timestamps (when the entry expires)
 */
class Put
{
    /**
     * Create a new put operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the put operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param mixed $value The value to store
     * @param int $seconds TTL in seconds; values below one are stored for one second
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @return bool True if successful
     */
    public function execute(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        $seconds = max(1, $seconds);

        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $seconds, $tagIds);
        }

        return $this->executePipeline($key, $value, $seconds, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * Pipelines SETEX and ZADD commands for all tags in a single round trip.
     */
    private function executePipeline(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tagIds): bool {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);
            $serialized = $this->serialization->serialize($connection, $value);

            $pipeline = $connection->pipeline();

            // Publish the value before its memberships so concurrent pruning
            // cannot mistake a newly written member for an orphan.
            $pipeline->setex($prefix . $key, $seconds, $serialized);

            // ZADD to each tag's sorted set
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, $score, $key);
            }

            $results = $pipeline->exec();

            // First result is the SETEX - check it succeeded
            return $results !== false && ($results[0] ?? false) !== false;
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
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tagIds): bool {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);
            $serialized = $this->serialization->serialize($connection, $value);

            // Publish the value before its memberships so concurrent pruning
            // can repair cross-slot races without losing fresh metadata.
            if (! $connection->setex($prefix . $key, $seconds, $serialized)) {
                return false;
            }

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $connection->zadd($prefix . $tagId, $score, $key);
            }

            return true;
        });
    }
}
