<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\Operations\SafeScan;
use Hypervel\Redis\PhpRedis;
use Hypervel\Redis\RedisConnection;

/**
 * Prune stale and orphaned entries from all-mode tag sorted sets.
 *
 * Forever items use a negative score, so expiry pruning preserves them.
 */
class Prune
{
    /**
     * Default number of keys to process per SCAN iteration.
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
     * The empty_sets_deleted statistic includes sets found already empty or
     * absent. Redis deletes a sorted set automatically with its final member.
     *
     * @param int $scanCount Number of keys per SCAN/ZSCAN iteration
     * @return array{tags_scanned: int, stale_entries_removed: int, entries_checked: int, orphans_removed: int, empty_sets_deleted: int}
     */
    public function execute(int $scanCount = self::DEFAULT_SCAN_COUNT): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($scanCount): array {
            $pattern = $this->context->tagScanPattern();
            $optPrefix = $this->context->optPrefix();
            $prefix = $this->context->prefix();
            $now = time();

            $statistics = [
                'tags_scanned' => 0,
                'stale_entries_removed' => 0,
                'entries_checked' => 0,
                'orphans_removed' => 0,
                'empty_sets_deleted' => 0,
            ];

            // Use SafeScan to handle OPT_PREFIX correctly
            $safeScan = new SafeScan($connection, $optPrefix);

            foreach ($safeScan->execute($pattern, $scanCount) as $tagKey) {
                ++$statistics['tags_scanned'];

                // Step 1: Remove TTL-expired entries (stale by time)
                $staleRemoved = $connection->zRemRangeByScore($tagKey, '0', (string) $now);
                $statistics['stale_entries_removed'] += is_int($staleRemoved) ? $staleRemoved : 0;

                // Step 2: Remove orphaned entries (cache key doesn't exist)
                $orphanResult = $this->removeOrphanedEntries($connection, $tagKey, $prefix, $scanCount);
                $statistics['entries_checked'] += $orphanResult['checked'];
                $statistics['orphans_removed'] += $orphanResult['removed'];

                // Step 3: Count sets deleted by their final member removal
                if ($connection->zCard($tagKey) === 0) {
                    ++$statistics['empty_sets_deleted'];
                }
            }

            return $statistics;
        });
    }

    /**
     * Remove orphaned entries from a sorted set where the cache key no longer exists.
     *
     * @param string $tagKey The tag sorted set key (without OPT_PREFIX, phpredis auto-adds it)
     * @param string $prefix The cache prefix (e.g., "cache:")
     * @param int $scanCount Number of members per ZSCAN iteration
     * @return array{checked: int, removed: int}
     */
    private function removeOrphanedEntries(
        RedisConnection $connection,
        string $tagKey,
        string $prefix,
        int $scanCount,
    ): array {
        $checked = 0;
        $removed = 0;
        $isCluster = $connection->isCluster();

        $iterator = PhpRedis::initialScanCursor();

        do {
            // Tag members omit OPT_PREFIX, so scan every member without prefixing the pattern.
            $members = $connection->withoutScanPrefix(function () use ($connection, $tagKey, &$iterator, $scanCount): mixed {
                return $connection->zScan($tagKey, $iterator, '*', $scanCount);
            });

            if ($members === false || ! is_array($members)) {
                break;
            }

            if ($members === []) {
                continue;
            }

            $memberKeys = array_keys($members);
            $checked += count($memberKeys);

            if (! $isCluster) {
                $keys = [$tagKey];

                foreach ($memberKeys as $key) {
                    $keys[] = $prefix . $key;
                }

                $pageRemoved = $connection->evalWithShaCache(
                    $this->removeOrphanedMembersScript(),
                    $keys,
                    $memberKeys,
                );

                $removed += is_int($pageRemoved) ? $pageRemoved : 0;
                continue;
            }

            $orphanedMembers = [];

            foreach ($memberKeys as $key) {
                if (! $this->keyExists($connection, $prefix . $key)) {
                    $orphanedMembers[] = $key;
                }
            }

            if ($orphanedMembers === []) {
                continue;
            }

            $pageRemoved = $connection->zrem($tagKey, ...$orphanedMembers);

            if (! is_int($pageRemoved)) {
                continue;
            }

            $repaired = 0;

            foreach ($orphanedMembers as $key) {
                // Cross-slot checks cannot be atomic in Cluster. A writer
                // publishes the value before membership, so restore only if
                // the value appeared. Its namespaced key encodes this tag set,
                // so no separate reverse-index check is needed. Preserve any
                // score the writer already republished.
                if ($this->keyExists($connection, $prefix . $key)) {
                    $connection->zadd($tagKey, ['NX'], -1, $key);
                    ++$repaired;
                }
            }

            // A batch cannot identify which member each concurrent repair
            // replaced, so statistics can under-count only during that race.
            $removed += max(0, $pageRemoved - $repaired);
        } while ($iterator !== 0);

        return [
            'checked' => $checked,
            'removed' => $removed,
        ];
    }

    /**
     * Remove members only while their corresponding cache keys are absent.
     */
    protected function removeOrphanedMembersScript(): string
    {
        return <<<'LUA'
            local tagSet = KEYS[1]
            local removed = 0

            for i = 2, #KEYS do
                if redis.call('EXISTS', KEYS[i]) == 0 then
                    removed = removed + redis.call('ZREM', tagSet, ARGV[i - 1])
                end
            end

            return removed
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
}
