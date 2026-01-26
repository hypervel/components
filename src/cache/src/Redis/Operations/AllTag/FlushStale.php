<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Flushes stale entries from tag sorted sets.
 *
 * Uses ZREMRANGEBYSCORE to remove entries whose TTL timestamps have passed.
 * This is a cleanup operation that can be called on specific tags via
 * Cache::tags(['users'])->flushStale() or globally via the prune command.
 *
 * Entries with score -1 (forever items) are never flushed.
 */
class FlushStale
{
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Flush stale entries from the given tag sorted sets.
     *
     * Removes entries with TTL scores between 0 and current timestamp.
     * Entries with score -1 (forever items) are not affected.
     *
     * In cluster mode, uses sequential commands since RedisCluster
     * doesn't support pipeline mode and tags may be in different slots.
     *
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     */
    public function execute(array $tagIds): void
    {
        if (empty($tagIds)) {
            return;
        }

        // Cluster mode: RedisCluster doesn't support pipeline, and tags
        // may be in different slots requiring sequential commands
        if ($this->context->isCluster()) {
            $this->executeCluster($tagIds);
            return;
        }

        $this->executePipeline($tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     */
    private function executePipeline(array $tagIds): void
    {
        $this->context->withConnection(function (RedisConnection $connection) use ($tagIds) {
            $prefix = $this->context->prefix();
            $timestamp = (string) now()->getTimestamp();

            $pipeline = $connection->pipeline();

            foreach ($tagIds as $tagId) {
                $pipeline->zRemRangeByScore(
                    $prefix . $tagId,
                    '0',
                    $timestamp
                );
            }

            $pipeline->exec();
        });
    }

    /**
     * Execute using multi() for Redis Cluster.
     *
     * RedisCluster doesn't support pipeline(), but multi() works across slots:
     * - Tracks which nodes receive commands
     * - Sends MULTI to each node lazily (on first key for that node)
     * - Executes EXEC on all involved nodes
     * - Aggregates results into a single array
     */
    private function executeCluster(array $tagIds): void
    {
        $this->context->withConnection(function (RedisConnection $connection) use ($tagIds) {
            $prefix = $this->context->prefix();
            $timestamp = (string) now()->getTimestamp();

            $multi = $connection->multi();

            foreach ($tagIds as $tagId) {
                $multi->zRemRangeByScore(
                    $prefix . $tagId,
                    '0',
                    $timestamp
                );
            }

            $multi->exec();
        });
    }
}
