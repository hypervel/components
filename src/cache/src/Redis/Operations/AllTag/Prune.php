<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\Operations\SafeScan;
use Hypervel\Redis\RedisConnection;
use Redis;
use RedisCluster;

/**
 * Prune stale and orphaned entries from all tag sorted sets.
 *
 * This operation performs a complete cleanup of all-mode tag data:
 * 1. Discovers all tag sorted sets via SCAN (pattern from StoreContext::tagScanPattern())
 * 2. Removes stale entries via ZREMRANGEBYSCORE (scores between 0 and now)
 * 3. Removes orphaned entries where the cache key no longer exists (ZSCAN + EXISTS + ZREM)
 * 4. Deletes empty sorted sets (ZCARD == 0)
 *
 * Forever items (score = -1) are preserved since ZREMRANGEBYSCORE uses 0 as
 * the lower bound, excluding negative scores.
 *
 * @see https://redis.io/commands/scan/
 * @see https://redis.io/commands/zremrangebyscore/
 */
class Prune
{
    /**
     * Default number of keys to process per SCAN iteration.
     */
    private const DEFAULT_SCAN_COUNT = 1000;

    /**
     * Create a new prune operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the prune operation.
     *
     * @param int $scanCount Number of keys per SCAN/ZSCAN iteration
     * @return array{tags_scanned: int, stale_entries_removed: int, entries_checked: int, orphans_removed: int, empty_sets_deleted: int}
     */
    public function execute(int $scanCount = self::DEFAULT_SCAN_COUNT): array
    {
        $isCluster = $this->context->isCluster();

        return $this->context->withConnection(function (RedisConnection $conn) use ($scanCount, $isCluster) {
            $client = $conn->client();
            $pattern = $this->context->tagScanPattern();
            $optPrefix = $this->context->optPrefix();
            $prefix = $this->context->prefix();
            $now = time();

            $stats = [
                'tags_scanned' => 0,
                'stale_entries_removed' => 0,
                'entries_checked' => 0,
                'orphans_removed' => 0,
                'empty_sets_deleted' => 0,
            ];

            // Use SafeScan to handle OPT_PREFIX correctly
            $safeScan = new SafeScan($client, $optPrefix);

            foreach ($safeScan->execute($pattern, $scanCount) as $tagKey) {
                ++$stats['tags_scanned'];

                // Step 1: Remove TTL-expired entries (stale by time)
                $staleRemoved = $client->zRemRangeByScore($tagKey, '0', (string) $now);
                $stats['stale_entries_removed'] += is_int($staleRemoved) ? $staleRemoved : 0;

                // Step 2: Remove orphaned entries (cache key doesn't exist)
                $orphanResult = $this->removeOrphanedEntries($client, $tagKey, $prefix, $scanCount, $isCluster);
                $stats['entries_checked'] += $orphanResult['checked'];
                $stats['orphans_removed'] += $orphanResult['removed'];

                // Step 3: Delete if empty
                if ($client->zCard($tagKey) === 0) {
                    $client->del($tagKey);
                    ++$stats['empty_sets_deleted'];
                }

                // Throttle between tags to let Redis breathe
                usleep(5000); // 5ms
            }

            return $stats;
        });
    }

    /**
     * Remove orphaned entries from a sorted set where the cache key no longer exists.
     *
     * @param string $tagKey The tag sorted set key (without OPT_PREFIX, phpredis auto-adds it)
     * @param string $prefix The cache prefix (e.g., "cache:")
     * @param int $scanCount Number of members per ZSCAN iteration
     * @param bool $isCluster Whether we're connected to a Redis Cluster
     * @return array{checked: int, removed: int}
     */
    private function removeOrphanedEntries(
        Redis|RedisCluster $client,
        string $tagKey,
        string $prefix,
        int $scanCount,
        bool $isCluster,
    ): array {
        $checked = 0;
        $removed = 0;

        // phpredis 6.1.0+ uses null as initial cursor, older versions use 0
        $iterator = match (true) {
            version_compare(phpversion('redis') ?: '0', '6.1.0', '>=') => null,
            default => 0,
        };

        do {
            // ZSCAN returns [member => score, ...] array
            $members = $client->zScan($tagKey, $iterator, '*', $scanCount);

            if ($members === false || ! is_array($members) || empty($members)) {
                break;
            }

            $memberKeys = array_keys($members);
            $checked += count($memberKeys);

            // Check which keys exist:
            // - Standard Redis: pipeline() batches commands with less overhead
            // - Cluster: multi() handles cross-slot commands (pipeline not supported)
            $batch = $isCluster ? $client->multi() : $client->pipeline();

            foreach ($memberKeys as $key) {
                $batch->exists($prefix . $key);
            }

            $existsResults = $batch->exec();

            // Collect orphaned members (cache key doesn't exist)
            $orphanedMembers = [];

            foreach ($memberKeys as $index => $key) {
                // EXISTS returns int (0 or 1)
                if (empty($existsResults[$index])) {
                    $orphanedMembers[] = $key;
                }
            }

            // Remove orphaned members from the sorted set
            if (! empty($orphanedMembers)) {
                $client->zRem($tagKey, ...$orphanedMembers);
                $removed += count($orphanedMembers);
            }
        } while ($iterator > 0);

        return [
            'checked' => $checked,
            'removed' => $removed,
        ];
    }
}
