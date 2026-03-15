<?php

declare(strict_types=1);

namespace Hypervel\Redis\Operations;

use Generator;
use Hypervel\Redis\PhpRedis;
use Hypervel\Redis\RedisConnection;

/**
 * Safely scan the Redis keyspace for keys matching a pattern.
 *
 * This class provides a memory-efficient iterator over keys using the SCAN command,
 * correctly handling the complexity of Redis OPT_PREFIX configuration and Redis Cluster.
 *
 * ## The OPT_PREFIX Problem
 *
 * phpredis has an OPT_PREFIX option that automatically prepends a prefix to keys
 * for most commands (GET, SET, DEL, etc.). However, this creates complexity:
 *
 * 1. **SCAN does NOT auto-prefix the pattern** - You must manually include OPT_PREFIX
 *    in your SCAN pattern to match keys that were stored with auto-prefixing.
 *
 * 2. **SCAN returns full keys** - Keys returned include the OPT_PREFIX as stored in Redis.
 *
 * 3. **DEL DOES auto-prefix** - If you pass a SCAN result directly to DEL, phpredis
 *    adds OPT_PREFIX again, causing double-prefixing and failed deletions.
 *
 * ## Example of the Bug This Class Prevents
 *
 * ```
 * OPT_PREFIX = "myapp:"
 * Stored key in Redis = "myapp:cache:user:1"
 *
 * // WRONG approach (what broken code does):
 * $keys = $redis->scan($iter, "myapp:cache:*");  // Returns ["myapp:cache:user:1"]
 * $redis->del($keys[0]);  // Tries to delete "myapp:myapp:cache:user:1" - FAILS!
 *
 * // CORRECT approach (what SafeScan does):
 * $keys = $redis->scan($iter, "myapp:cache:*");  // Returns ["myapp:cache:user:1"]
 * $strippedKey = substr($keys[0], strlen("myapp:"));  // "cache:user:1"
 * $redis->del($strippedKey);  // phpredis adds prefix -> deletes "myapp:cache:user:1" - SUCCESS!
 * ```
 *
 * ## Redis Cluster Support
 *
 * Redis Cluster requires scanning each master node separately because keys are
 * distributed across slots on different nodes. This class handles this automatically:
 *
 * - For standard Redis: Uses `scan($iter, $pattern, $count)`
 * - For RedisCluster: Iterates `_masters()` and uses `scan($iter, $node, $pattern, $count)`
 *
 * ## Usage
 *
 * This class is designed to be used within a connection pool callback:
 *
 * ```php
 * $context->withConnection(function (RedisConnection $connection) {
 *     $optPrefix = (string) $connection->getOption(Redis::OPT_PREFIX);
 *     $safeScan = new SafeScan($connection, $optPrefix);
 *     foreach ($safeScan->execute('cache:users:*') as $key) {
 *         // $key is stripped of OPT_PREFIX, safe to use with del(), get(), etc.
 *     }
 * });
 * ```
 */
final class SafeScan
{
    /**
     * Create a new safe scan instance.
     *
     * @param RedisConnection $connection The Redis connection (with transform: false for raw phpredis semantics)
     * @param string $optPrefix The OPT_PREFIX value (from $connection->getOption(Redis::OPT_PREFIX))
     */
    public function __construct(
        private readonly RedisConnection $connection,
        private readonly string $optPrefix,
    ) {
    }

    /**
     * Execute the scan operation.
     *
     * @param string $pattern The pattern to match (e.g., "cache:users:*").
     *                        Should NOT include OPT_PREFIX - it will be added automatically.
     * @param int $count The COUNT hint for SCAN (not a limit, just a hint to Redis)
     * @return Generator<string> yields keys with OPT_PREFIX stripped, safe for use with
     *                           other phpredis commands that auto-add the prefix
     */
    public function execute(string $pattern, int $count = 1000): Generator
    {
        $prefixLen = strlen($this->optPrefix);

        // SCAN does not automatically apply OPT_PREFIX to the pattern,
        // so we must prepend it manually to match keys stored with auto-prefixing.
        $scanPattern = $pattern;
        if ($prefixLen > 0 && ! str_starts_with($pattern, $this->optPrefix)) {
            $scanPattern = $this->optPrefix . $pattern;
        }

        // Route to cluster or standard implementation
        if ($this->connection->isCluster()) {
            yield from $this->scanCluster($scanPattern, $count, $prefixLen);
        } else {
            yield from $this->scanStandard($scanPattern, $count, $prefixLen);
        }
    }

    /**
     * Scan a standard (non-cluster) Redis instance.
     */
    private function scanStandard(string $scanPattern, int $count, int $prefixLen): Generator
    {
        $iterator = PhpRedis::initialScanCursor();

        do {
            // SCAN returns keys as they exist in Redis (with full prefix)
            $keys = $this->connection->scan($iterator, $scanPattern, $count);

            // Normalize result (phpredis returns false on failure/empty)
            if ($keys === false || ! is_array($keys)) {
                $keys = [];
            }

            // Yield keys with OPT_PREFIX stripped so they can be used directly
            // with other phpredis commands that auto-add the prefix.
            // NOTE: We inline this loop instead of using `yield from` a sub-generator
            // because `yield from` would reset auto-increment keys for each batch,
            // causing key collisions when the result is passed to iterator_to_array().
            foreach ($keys as $key) {
                if ($prefixLen > 0 && str_starts_with($key, $this->optPrefix)) {
                    yield substr($key, $prefixLen);
                } else {
                    yield $key;
                }
            }
        } while ($iterator > 0);
    }

    /**
     * Scan a Redis Cluster by iterating all master nodes.
     *
     * RedisCluster::scan() has a different signature that requires specifying
     * which node to scan. We must iterate all masters to find all keys.
     */
    private function scanCluster(string $scanPattern, int $count, int $prefixLen): Generator
    {
        // Get all master nodes in the cluster
        // @phpstan-ignore method.notFound (RedisCluster-specific method, available when isCluster() is true)
        $masters = $this->connection->_masters();

        foreach ($masters as $master) {
            // Each master node needs its own cursor
            $iterator = PhpRedis::initialScanCursor();

            do {
                // RedisCluster::scan() signature: scan(&$iter, $node, $pattern, $count)
                $keys = $this->connection->scan($iterator, $master, $scanPattern, $count);

                // Normalize result (phpredis returns false on failure/empty)
                if ($keys === false || ! is_array($keys)) {
                    $keys = [];
                }

                // Yield keys with OPT_PREFIX stripped (see comment in scanStandard)
                foreach ($keys as $key) {
                    if ($prefixLen > 0 && str_starts_with($key, $this->optPrefix)) {
                        yield substr($key, $prefixLen);
                    } else {
                        yield $key;
                    }
                }
            } while ($iterator > 0);
        }
    }
}
