<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\PhpRedis;
use Hypervel\Redis\RedisConnection;

/**
 * Prune orphaned fields and registry entries from any-mode tag hashes.
 *
 * Lazy flush can leave hash fields behind when cache values are deleted
 * directly instead of through the tagged cache.
 */
class Prune
{
    /**
     * Default number of hash fields to process per HSCAN iteration.
     */
    private const int DEFAULT_SCAN_COUNT = 1000;

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
     * The empty_hashes_deleted statistic includes hashes found already empty or
     * absent. Redis deletes a hash automatically when its final field is removed.
     *
     * @param int $scanCount Number of fields per HSCAN iteration
     * @return array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int, orphaned_tags_removed: int}
     */
    public function execute(int $scanCount = self::DEFAULT_SCAN_COUNT): array
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($scanCount);
        }

        return $this->executeUsingLua($scanCount);
    }

    /**
     * Execute using bounded Lua operations for standard Redis.
     *
     * @return array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int, orphaned_tags_removed: int}
     */
    private function executeUsingLua(int $scanCount): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($scanCount) {
            $prefix = $this->context->prefix();
            $registryKey = $this->context->registryKey();
            $now = time();

            $stats = [
                'hashes_scanned' => 0,
                'fields_checked' => 0,
                'orphans_removed' => 0,
                'empty_hashes_deleted' => 0,
                'expired_tags_removed' => 0,
                'orphaned_tags_removed' => 0,
            ];

            // Step 1: Remove expired tags from registry
            $expiredCount = $connection->zRemRangeByScore($registryKey, '-inf', (string) $now);
            $stats['expired_tags_removed'] = is_int($expiredCount) ? $expiredCount : 0;

            // Step 2: Get active tags from registry
            $tags = $connection->zRange($registryKey, 0, -1);

            if (empty($tags) || ! is_array($tags)) {
                return $stats;
            }

            // Step 3: Process each tag hash
            foreach ($tags as $tag) {
                $tagHash = $this->context->tagHashKey($tag);
                $result = $this->cleanupTagHashUsingLua($connection, (string) $tag, $tagHash, $prefix, $scanCount);

                ++$stats['hashes_scanned'];
                $stats['fields_checked'] += $result['checked'];
                $stats['orphans_removed'] += $result['removed'];

                if ($result['deleted']) {
                    ++$stats['empty_hashes_deleted'];
                }

                $stats['orphaned_tags_removed'] += $result['registry_removed'];
            }

            return $stats;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * @return array{hashes_scanned: int, fields_checked: int, orphans_removed: int, empty_hashes_deleted: int, expired_tags_removed: int, orphaned_tags_removed: int}
     */
    private function executeCluster(int $scanCount): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($scanCount) {
            $prefix = $this->context->prefix();
            $registryKey = $this->context->registryKey();
            $now = time();

            $stats = [
                'hashes_scanned' => 0,
                'fields_checked' => 0,
                'orphans_removed' => 0,
                'empty_hashes_deleted' => 0,
                'expired_tags_removed' => 0,
                'orphaned_tags_removed' => 0,
            ];

            // Step 1: Remove expired tags from registry
            $expiredCount = $connection->zRemRangeByScore($registryKey, '-inf', (string) $now);
            $stats['expired_tags_removed'] = is_int($expiredCount) ? $expiredCount : 0;

            // Step 2: Get active tags from registry
            $tags = $connection->zRange($registryKey, 0, -1);

            if (empty($tags) || ! is_array($tags)) {
                return $stats;
            }

            // Step 3: Process each tag hash
            foreach ($tags as $tag) {
                $tagHash = $this->context->tagHashKey($tag);
                $result = $this->cleanupTagHashCluster($connection, (string) $tag, $tagHash, $prefix, $scanCount);

                ++$stats['hashes_scanned'];
                $stats['fields_checked'] += $result['checked'];
                $stats['orphans_removed'] += $result['removed'];

                if ($result['deleted']) {
                    ++$stats['empty_hashes_deleted'];
                }

                $stats['orphaned_tags_removed'] += $result['registry_removed'];
            }

            return $stats;
        });
    }

    /**
     * Clean up orphaned fields from a single tag hash atomically in bounded pages.
     *
     * @return array{checked: int, removed: int, deleted: bool, registry_removed: int}
     */
    private function cleanupTagHashUsingLua(
        RedisConnection $connection,
        string $tag,
        string $tagHash,
        string $prefix,
        int $scanCount,
    ): array {
        $checked = 0;
        $removed = 0;

        $iterator = PhpRedis::initialScanCursor();

        do {
            // Tag fields omit OPT_PREFIX, so scan every field without prefixing the pattern.
            $fields = $connection->withoutScanPrefix(function () use ($connection, $tagHash, &$iterator, $scanCount): mixed {
                return $connection->hScan($tagHash, $iterator, '*', $scanCount);
            });

            if ($fields === false || ! is_array($fields)) {
                break;
            }

            if ($fields === []) {
                continue;
            }

            $fieldKeys = array_keys($fields);
            $checked += count($fieldKeys);

            $keys = [$tagHash];

            foreach ($fieldKeys as $key) {
                $keys[] = $prefix . $key;
            }

            $pageRemoved = $connection->evalWithShaCache(
                $this->removeOrphanedFieldsScript(),
                $keys,
                $fieldKeys,
            );

            $removed += is_int($pageRemoved) ? $pageRemoved : 0;
        } while ($iterator !== 0);

        $finalized = $connection->evalWithShaCache(
            $this->removeEmptyTagFromRegistryScript(),
            [$tagHash, $this->context->registryKey()],
            [$tag],
        );

        return [
            'checked' => $checked,
            'removed' => $removed,
            'deleted' => is_array($finalized) && ($finalized[0] ?? 0) === 1,
            'registry_removed' => is_array($finalized) && is_int($finalized[1] ?? null) ? $finalized[1] : 0,
        ];
    }

    /**
     * Clean up orphaned fields from a single tag hash using sequential commands (cluster mode).
     *
     * @return array{checked: int, removed: int, deleted: bool, registry_removed: int}
     */
    private function cleanupTagHashCluster(
        RedisConnection $connection,
        string $tag,
        string $tagHash,
        string $prefix,
        int $scanCount,
    ): array {
        $checked = 0;
        $removed = 0;

        $iterator = PhpRedis::initialScanCursor();

        do {
            // Tag fields omit OPT_PREFIX, so scan every field without prefixing the pattern.
            $fields = $connection->withoutScanPrefix(function () use ($connection, $tagHash, &$iterator, $scanCount): mixed {
                return $connection->hScan($tagHash, $iterator, '*', $scanCount);
            });

            if ($fields === false || ! is_array($fields)) {
                break;
            }

            if ($fields === []) {
                continue;
            }

            $fieldKeys = array_keys($fields);
            $checked += count($fieldKeys);
            $orphanedFields = [];

            foreach ($fieldKeys as $key) {
                if (! $this->keyExists($connection, $prefix . $key)) {
                    $orphanedFields[] = $key;
                }
            }

            if ($orphanedFields === []) {
                continue;
            }

            $pageRemoved = $connection->hDel($tagHash, ...$orphanedFields);

            if (! is_int($pageRemoved)) {
                continue;
            }

            $repaired = 0;

            foreach ($orphanedFields as $key) {
                // Cross-slot checks cannot be atomic in Cluster. Restore only
                // when the value and its reverse index prove a writer won the
                // race; HSETNX preserves metadata already republished by it.
                if ($this->keyExists($connection, $prefix . $key)
                    && $connection->sismember($this->context->reverseIndexKey($key), $tag)) {
                    $connection->hsetnx($tagHash, $key, StoreContext::TAG_FIELD_VALUE);
                    ++$repaired;
                }
            }

            // A batch cannot identify which field each concurrent repair
            // replaced, so statistics can under-count only during that race.
            $removed += max(0, $pageRemoved - $repaired);
        } while ($iterator !== 0);

        $deleted = false;
        $registryRemoved = 0;

        if ($this->tagHashIsEmpty($connection, $tagHash)) {
            $registryRemoved = (int) $connection->zrem($this->context->registryKey(), $tag);
            $deleted = true;

            // A writer publishes the hash before the registry. If it revived
            // the hash during the cross-slot cleanup, restore only a missing
            // registry member and let the writer's real expiry win.
            if (! $this->tagHashIsEmpty($connection, $tagHash)) {
                $connection->zadd(
                    $this->context->registryKey(),
                    ['NX'],
                    StoreContext::MAX_EXPIRY,
                    $tag,
                );

                $deleted = false;
                $registryRemoved = 0;
            }
        }

        return [
            'checked' => $checked,
            'removed' => $removed,
            'deleted' => $deleted,
            'registry_removed' => $registryRemoved,
        ];
    }

    /**
     * Remove fields only while their corresponding cache keys are absent.
     */
    protected function removeOrphanedFieldsScript(): string
    {
        return <<<'LUA'
            local tagHash = KEYS[1]
            local removed = 0

            for i = 2, #KEYS do
                if redis.call('EXISTS', KEYS[i]) == 0 then
                    removed = removed + redis.call('HDEL', tagHash, ARGV[i - 1])
                end
            end

            return removed
            LUA;
    }

    /**
     * Remove an empty tag from the registry in the same atomic operation.
     */
    protected function removeEmptyTagFromRegistryScript(): string
    {
        return <<<'LUA'
            if redis.call('HLEN', KEYS[1]) == 0 then
                return {1, redis.call('ZREM', KEYS[2], ARGV[1])}
            end

            return {0, 0}
            LUA;
    }

    /**
     * Check the current Redis state without treating consecutive reads as stable.
     *
     * @phpstan-impure Redis may change between calls.
     */
    private function keyExists(RedisConnection $connection, string $key): bool
    {
        return (bool) $connection->exists($key);
    }

    /**
     * Check the current hash state without treating consecutive reads as stable.
     *
     * @phpstan-impure Redis may change between calls.
     */
    private function tagHashIsEmpty(RedisConnection $connection, string $tagHash): bool
    {
        return $connection->hlen($tagHash) === 0;
    }
}
