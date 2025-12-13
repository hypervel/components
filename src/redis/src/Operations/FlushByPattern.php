<?php

declare(strict_types=1);

namespace Hypervel\Redis\Operations;

use Hypervel\Redis\RedisConnection;
use Redis;

/**
 * Flush (delete) Redis keys matching a pattern.
 *
 * This class uses SafeScan to iterate keys efficiently and deletes them in batches.
 * It correctly handles OPT_PREFIX to avoid the double-prefixing bug.
 *
 * ## Why This Exists
 *
 * Pattern-based key deletion is needed for cleanup operations (tests, benchmarks,
 * cache invalidation by prefix). However, phpredis OPT_PREFIX makes this tricky:
 *
 * - SCAN doesn't auto-add OPT_PREFIX to patterns
 * - SCAN returns keys WITH the full prefix as stored
 * - DEL auto-adds OPT_PREFIX to key names
 *
 * Without SafeScan, you'd try to delete "prefix:prefix:key" instead of "prefix:key".
 *
 * ## Usage
 *
 * Typically used via RedisConnection convenience method:
 *
 * ```php
 * // Via connection method (recommended)
 * $connection->flushByPattern('cache:users:*');
 *
 * // Via Redis facade (handles connection lifecycle)
 * Redis::flushByPattern('cache:users:*');
 *
 * // Direct instantiation (when you have a held connection)
 * $flushByPattern = new FlushByPattern($connection);
 * $deletedCount = $flushByPattern->execute('cache:users:*');
 * ```
 *
 * ## Warning
 *
 * When used with cache, this bypasses tag management. Only use for:
 * - Non-tagged items
 * - Administrative cleanup where orphaned tag references are acceptable
 * - Test/benchmark data cleanup
 */
final class FlushByPattern
{
    /**
     * Number of keys to buffer before executing a batch delete.
     * Balances memory usage vs. number of Redis round-trips.
     */
    private const BUFFER_SIZE = 1000;

    /**
     * Create a new pattern flush instance.
     *
     * @param RedisConnection $connection A held Redis connection (not released until done)
     */
    public function __construct(
        private readonly RedisConnection $connection,
    ) {
    }

    /**
     * Execute the pattern flush operation.
     *
     * @param string $pattern The pattern to match (e.g., "cache:test:*").
     *                        Should NOT include OPT_PREFIX - it's handled automatically.
     * @return int Number of keys deleted
     */
    public function execute(string $pattern): int
    {
        $client = $this->connection->client();
        $optPrefix = (string) $client->getOption(Redis::OPT_PREFIX);

        $safeScan = new SafeScan($client, $optPrefix);

        $deletedCount = 0;
        $buffer = [];

        // Iterate using the memory-safe generator
        foreach ($safeScan->execute($pattern) as $key) {
            $buffer[] = $key;

            if (count($buffer) >= self::BUFFER_SIZE) {
                $deletedCount += $this->deleteKeys($buffer);
                $buffer = [];
            }
        }

        // Delete any remaining keys in the buffer
        if (! empty($buffer)) {
            $deletedCount += $this->deleteKeys($buffer);
        }

        return $deletedCount;
    }

    /**
     * Delete a batch of keys.
     *
     * Uses UNLINK (async delete) when available for better performance,
     * falls back to DEL for older Redis versions.
     *
     * @param array<string> $keys Keys to delete (without OPT_PREFIX - phpredis adds it)
     * @return int Number of keys deleted
     */
    private function deleteKeys(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        // UNLINK is non-blocking (async) delete, available since Redis 4.0
        $result = $this->connection->unlink(...$keys);

        return is_int($result) ? $result : 0;
    }
}
