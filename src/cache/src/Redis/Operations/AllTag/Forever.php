<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache indefinitely with all tag tracking.
 *
 * Combines SET cache storage with the ZADD tag tracking operations in a
 * single connection checkout for efficiency.
 *
 * Forever items use a score of -1 in the tag sorted sets, which
 * prevents them from being cleaned by ZREMRANGEBYSCORE operations.
 */
class Forever
{
    private const int FOREVER_SCORE = -1;

    /**
     * Create a new forever operation instance.
     */
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
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $tagIds): bool {
            $prefix = $this->context->prefix();
            $serialized = $this->serialization->serialize($connection, $value);

            $pipeline = $connection->pipeline();

            // Publish the value before its memberships so concurrent pruning
            // cannot mistake a newly written member for an orphan.
            $pipeline->set($prefix . $key, $serialized);

            // ZADD to each tag's sorted set with score -1 (forever)
            foreach ($tagIds as $tagId) {
                $pipeline->zadd($prefix . $tagId, self::FOREVER_SCORE, $key);
            }

            $results = $pipeline->exec();

            return $results !== false && ! in_array(false, $results, true);
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     */
    private function executeCluster(string $key, mixed $value, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $tagIds): bool {
            $prefix = $this->context->prefix();
            $serialized = $this->serialization->serialize($connection, $value);

            // Publish the value before its memberships so concurrent pruning
            // can repair cross-slot races without losing fresh metadata.
            if (! $connection->set($prefix . $key, $serialized)) {
                return false;
            }

            $membershipsSucceeded = true;

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                if ($connection->zadd($prefix . $tagId, self::FOREVER_SCORE, $key) === false) {
                    $membershipsSucceeded = false;
                }
            }

            return $membershipsSucceeded;
        });
    }
}
