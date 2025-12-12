<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;
use Redis;
use RedisCluster;

/**
 * Prune orphaned fields from any tag hashes.
 *
 * This operation performs a complete cleanup of any-mode tag data:
 * 1. Removes expired tags from the registry (ZREMRANGEBYSCORE)
 * 2. Gets active tags from the registry (ZRANGE)
 * 3. Scans each tag hash for orphaned fields (HSCAN + EXISTS checks)
 * 4. Removes orphaned fields where the cache key no longer exists (HDEL)
 * 5. Deletes empty hashes (HLEN == 0)
 *
 * This cleanup is needed because in lazy flush mode, when cache keys are
 * deleted directly (not via tag flush), the hash field references remain.
 *
 * @see https://redis.io/commands/zremrangebyscore/
 * @see https://redis.io/commands/hscan/
 */
class Prune
{
    /**
     * Default number of hash fields to process per HSCAN iteration.
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
     * @param int $scanCount Number of fields per HSCAN iteration
     * @return array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int}
     */
    public function execute(int $scanCount = self::DEFAULT_SCAN_COUNT): array
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($scanCount);
        }

        return $this->executePipeline($scanCount);
    }

    /**
     * Execute using pipeline for standard Redis.
     *
     * @return array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int}
     */
    private function executePipeline(int $scanCount): array
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($scanCount) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $registryKey = $this->context->registryKey();
            $now = time();

            $stats = [
                'hashes_scanned' => 0,
                'fields_checked' => 0,
                'orphans_removed' => 0,
                'empty_hashes_deleted' => 0,
                'expired_tags_removed' => 0,
            ];

            // Step 1: Remove expired tags from registry
            $expiredCount = $client->zRemRangeByScore($registryKey, '-inf', (string) $now);
            $stats['expired_tags_removed'] = is_int($expiredCount) ? $expiredCount : 0;

            // Step 2: Get active tags from registry
            $tags = $client->zRange($registryKey, 0, -1);

            if (empty($tags) || ! is_array($tags)) {
                return $stats;
            }

            // Step 3: Process each tag hash
            foreach ($tags as $tag) {
                $tagHash = $this->context->tagHashKey($tag);
                $result = $this->cleanupTagHashPipeline($client, $tagHash, $prefix, $scanCount);

                ++$stats['hashes_scanned'];
                $stats['fields_checked'] += $result['checked'];
                $stats['orphans_removed'] += $result['removed'];

                if ($result['deleted']) {
                    ++$stats['empty_hashes_deleted'];
                }

                // Small sleep to let Redis breathe between tag hashes
                usleep(5000); // 5ms
            }

            return $stats;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * @return array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int}
     */
    private function executeCluster(int $scanCount): array
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($scanCount) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $registryKey = $this->context->registryKey();
            $now = time();

            $stats = [
                'hashes_scanned' => 0,
                'fields_checked' => 0,
                'orphans_removed' => 0,
                'empty_hashes_deleted' => 0,
                'expired_tags_removed' => 0,
            ];

            // Step 1: Remove expired tags from registry
            $expiredCount = $client->zRemRangeByScore($registryKey, '-inf', (string) $now);
            $stats['expired_tags_removed'] = is_int($expiredCount) ? $expiredCount : 0;

            // Step 2: Get active tags from registry
            $tags = $client->zRange($registryKey, 0, -1);

            if (empty($tags) || ! is_array($tags)) {
                return $stats;
            }

            // Step 3: Process each tag hash
            foreach ($tags as $tag) {
                $tagHash = $this->context->tagHashKey($tag);
                $result = $this->cleanupTagHashCluster($client, $tagHash, $prefix, $scanCount);

                ++$stats['hashes_scanned'];
                $stats['fields_checked'] += $result['checked'];
                $stats['orphans_removed'] += $result['removed'];

                if ($result['deleted']) {
                    ++$stats['empty_hashes_deleted'];
                }

                // Small sleep to let Redis breathe between tag hashes
                usleep(5000); // 5ms
            }

            return $stats;
        });
    }

    /**
     * Clean up orphaned fields from a single tag hash using pipeline.
     *
     * @param Redis|RedisCluster $client
     * @return array{checked: int, removed: int, deleted: bool}
     */
    private function cleanupTagHashPipeline(mixed $client, string $tagHash, string $prefix, int $scanCount): array
    {
        $checked = 0;
        $removed = 0;

        // phpredis 6.1.0+ uses null as initial cursor, older versions use 0
        $iterator = match (true) {
            version_compare(phpversion('redis') ?: '0', '6.1.0', '>=') => null,
            default => 0,
        };

        do {
            // HSCAN returns [field => value, ...] array
            $fields = $client->hScan($tagHash, $iterator, '*', $scanCount);

            if ($fields === false || ! is_array($fields) || empty($fields)) {
                break;
            }

            $fieldKeys = array_keys($fields);
            $checked += count($fieldKeys);

            // Use pipeline to check existence of all cache keys
            $pipeline = $client->pipeline();
            foreach ($fieldKeys as $key) {
                $pipeline->exists($prefix . $key);
            }
            $existsResults = $pipeline->exec();

            // Collect orphaned fields (cache key doesn't exist)
            $orphanedFields = [];
            foreach ($fieldKeys as $index => $key) {
                if (! $existsResults[$index]) {
                    $orphanedFields[] = $key;
                }
            }

            // Remove orphaned fields
            if (! empty($orphanedFields)) {
                $client->hDel($tagHash, ...$orphanedFields);
                $removed += count($orphanedFields);
            }
        } while ($iterator > 0);

        // Check if hash is now empty and delete it
        $deleted = false;
        $hashLen = $client->hLen($tagHash);
        if ($hashLen === 0) {
            $client->del($tagHash);
            $deleted = true;
        }

        return [
            'checked' => $checked,
            'removed' => $removed,
            'deleted' => $deleted,
        ];
    }

    /**
     * Clean up orphaned fields from a single tag hash using sequential commands (cluster mode).
     *
     * @param Redis|RedisCluster $client
     * @return array{checked: int, removed: int, deleted: bool}
     */
    private function cleanupTagHashCluster(mixed $client, string $tagHash, string $prefix, int $scanCount): array
    {
        $checked = 0;
        $removed = 0;

        // phpredis 6.1.0+ uses null as initial cursor, older versions use 0
        $iterator = match (true) {
            version_compare(phpversion('redis') ?: '0', '6.1.0', '>=') => null,
            default => 0,
        };

        do {
            // HSCAN returns [field => value, ...] array
            $fields = $client->hScan($tagHash, $iterator, '*', $scanCount);

            if ($fields === false || ! is_array($fields) || empty($fields)) {
                break;
            }

            $fieldKeys = array_keys($fields);
            $checked += count($fieldKeys);

            // Check existence sequentially in cluster mode
            $orphanedFields = [];
            foreach ($fieldKeys as $key) {
                if (! $client->exists($prefix . $key)) {
                    $orphanedFields[] = $key;
                }
            }

            // Remove orphaned fields
            if (! empty($orphanedFields)) {
                $client->hDel($tagHash, ...$orphanedFields);
                $removed += count($orphanedFields);
            }
        } while ($iterator > 0);

        // Check if hash is now empty and delete it
        $deleted = false;
        $hashLen = $client->hLen($tagHash);
        if ($hashLen === 0) {
            $client->del($tagHash);
            $deleted = true;
        }

        return [
            'checked' => $checked,
            'removed' => $removed,
            'deleted' => $deleted,
        ];
    }
}
