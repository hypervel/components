<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Adds a cache key reference to all tag sorted sets.
 *
 * Each tag maintains a sorted set where:
 * - Members are cache keys
 * - Scores are TTL timestamps (or -1 for forever items)
 *
 * This allows efficient tag-based cache invalidation and cleanup
 * of expired entries via ZREMRANGEBYSCORE.
 */
class AddEntry
{
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Add a cache key entry to tag sorted sets.
     *
     * Uses pipeline when multiple tags are provided for efficiency.
     * In cluster mode, uses sequential commands since RedisCluster
     * doesn't support pipeline mode and tags may be in different slots.
     *
     * @param string $key The cache key (without prefix)
     * @param int $ttl TTL in seconds (0 means forever, stored as -1 score)
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @param null|string $updateWhen Optional ZADD flag: 'NX' (only add new), 'XX' (only update existing), 'GT'/'LT'
     */
    public function execute(string $key, int $ttl, array $tagIds, ?string $updateWhen = null): void
    {
        if (empty($tagIds)) {
            return;
        }

        // Convert TTL to timestamp score:
        // - If TTL > 0: timestamp when this entry expires
        // - If TTL <= 0: -1 to indicate "forever" (won't be cleaned by ZREMRANGEBYSCORE)
        $score = $ttl > 0 ? now()->addSeconds($ttl)->getTimestamp() : -1;

        // Cluster mode: RedisCluster doesn't support pipeline, and tags
        // may be in different slots requiring sequential commands
        if ($this->context->isCluster()) {
            $this->executeCluster($key, $score, $tagIds, $updateWhen);
            return;
        }

        $this->executePipeline($key, $score, $tagIds, $updateWhen);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     */
    private function executePipeline(string $key, int $score, array $tagIds, ?string $updateWhen): void
    {
        $this->context->withConnection(function (RedisConnection $conn) use ($key, $score, $tagIds, $updateWhen) {
            $prefix = $this->context->prefix();
            $pipeline = $conn->pipeline();

            foreach ($tagIds as $tagId) {
                $prefixedTagKey = $prefix . $tagId;

                if ($updateWhen) {
                    // ZADD with flag (NX, XX, GT, LT) - options must be array
                    $pipeline->zadd($prefixedTagKey, [$updateWhen], $score, $key);
                } else {
                    // Standard ZADD
                    $pipeline->zadd($prefixedTagKey, $score, $key);
                }
            }

            $pipeline->exec();
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Each tag sorted set may be in a different slot, so we must
     * execute ZADD commands sequentially rather than in a pipeline.
     */
    private function executeCluster(string $key, int $score, array $tagIds, ?string $updateWhen): void
    {
        $this->context->withConnection(function (RedisConnection $conn) use ($key, $score, $tagIds, $updateWhen) {
            $prefix = $this->context->prefix();

            foreach ($tagIds as $tagId) {
                $prefixedTagKey = $prefix . $tagId;

                if ($updateWhen) {
                    // ZADD with flag (NX, XX, GT, LT)
                    // RedisCluster requires options as array, not string
                    $conn->zadd($prefixedTagKey, [$updateWhen], $score, $key);
                } else {
                    // Standard ZADD
                    $conn->zadd($prefixedTagKey, $score, $key);
                }
            }
        });
    }
}
