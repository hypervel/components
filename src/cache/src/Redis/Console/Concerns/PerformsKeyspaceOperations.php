<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Concerns;

use Generator;
use Hypervel\Cache\Redis\Flush\FlushByPattern;
use Hypervel\Cache\Redis\Query\SafeScan;
use Hypervel\Cache\RedisStore;
use Hypervel\Redis\RedisConnection;
use Redis;

/**
 * Provides keyspace operations for console commands.
 *
 * These operations (SCAN, pattern-based deletion) are intentionally not part of
 * the public store API because they bypass tag management and can cause orphaned
 * references if used on tagged items. They are only intended for internal use
 * by commands that need to clean up test/benchmark data.
 *
 * ## OPT_PREFIX Handling
 *
 * phpredis has an OPT_PREFIX option that auto-prefixes keys for most commands,
 * but NOT for SCAN patterns. This creates a trap where naive implementations
 * cause double-prefixing bugs. This trait uses SafeScan internally to handle
 * this complexity correctly. See SafeScan for detailed explanation.
 *
 * @internal
 */
trait PerformsKeyspaceOperations
{
    /**
     * Flush keys matching a pattern.
     *
     * WARNING: This bypasses tag management. Only use for non-tagged items
     * or administrative cleanup where orphaned references are acceptable.
     *
     * The pattern should include the cache prefix but NOT the OPT_PREFIX.
     * OPT_PREFIX is handled automatically by FlushByPattern/SafeScan.
     *
     * Example:
     * ```php
     * // Delete all doctor test keys
     * $this->flushKeysByPattern($store, $store->getPrefix() . '_doctor:test:*');
     * // With prefix "cache:", this matches "cache:_doctor:test:*"
     * // If OPT_PREFIX is "myapp:", actual Redis keys are "myapp:cache:_doctor:test:*"
     * ```
     *
     * @param RedisStore $store The cache store instance
     * @param string $pattern The pattern to match, including cache prefix (e.g., "cache:benchmark:*")
     * @return int Number of keys deleted
     */
    protected function flushKeysByPattern(RedisStore $store, string $pattern): int
    {
        $context = $store->getContext();
        $flushByPattern = new FlushByPattern($context);

        return $flushByPattern->execute($pattern);
    }

    /**
     * Scan keys matching a pattern.
     *
     * The pattern should include the cache prefix but NOT the OPT_PREFIX.
     * OPT_PREFIX is handled automatically by SafeScan.
     *
     * Yields keys WITHOUT OPT_PREFIX, so they can be used directly with other
     * phpredis commands that auto-add the prefix.
     *
     * Note: This method holds a connection from the pool for the entire iteration.
     * For large keyspaces, consider using FlushByPattern which handles batching.
     *
     * @param RedisStore $store The cache store instance
     * @param string $pattern The pattern to match, including cache prefix
     * @param int $count The COUNT hint for SCAN (not a limit, just a hint to Redis)
     * @return Generator<string> Yields keys without OPT_PREFIX
     */
    protected function scanKeys(RedisStore $store, string $pattern, int $count = 1000): Generator
    {
        $context = $store->getContext();

        // We need to hold the connection for the entire scan operation
        // because SCAN is cursor-based and requires multiple round-trips.
        return $context->withConnection(function (RedisConnection $conn) use ($pattern, $count) {
            $client = $conn->client();
            $optPrefix = (string) $client->getOption(Redis::OPT_PREFIX);

            $safeScan = new SafeScan($client, $optPrefix);

            // Yield from the SafeScan generator
            yield from $safeScan->execute($pattern, $count);
        });
    }
}
