<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store multiple items in the cache (non-tagged).
 *
 * Optimizes Laravel's default putMany() by using a Lua script in standard mode,
 * reducing the number of commands Redis needs to parse.
 *
 * Performance:
 * - Standard mode: Single Lua script execution with evalSha caching
 * - Cluster mode: Uses MULTI/EXEC for transactional grouping per-node, but
 *   commands are sent sequentially (RedisCluster does not support pipelining)
 */
class PutMany
{
    /**
     * The Lua script for setting multiple keys with the same TTL.
     */
    private const LUA_SCRIPT = "local ttl = ARGV[1] local numKeys = #KEYS for i = 1, numKeys do redis.call('SETEX', KEYS[i], ttl, ARGV[i + 1]) end return true";

    /**
     * Create a new put many operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the putMany operation.
     *
     * @param array<string, mixed> $values Array of key => value pairs
     * @param int $seconds TTL in seconds
     * @return bool True if successful, false on failure
     */
    public function execute(array $values, int $seconds): bool
    {
        if (empty($values)) {
            return true;
        }

        // Cluster mode: Keys may hash to different slots, use MULTI + individual SETEX
        if ($this->context->isCluster()) {
            return $this->executeCluster($values, $seconds);
        }

        // Standard mode: Use Lua script for efficiency
        return $this->executeUsingLua($values, $seconds);
    }

    /**
     * Execute for cluster using MULTI/EXEC.
     *
     * In cluster mode, keys may hash to different slots. Unlike standalone Redis,
     * RedisCluster does NOT currently support pipelining - commands are sent sequentially
     * to each node as they are encountered. MULTI/EXEC still provides value by:
     *
     * 1. Grouping commands into transactions per-node (atomicity per slot)
     * 2. Aggregating results from all nodes into a single array on exec()
     * 3. Matching Laravel's default RedisStore behavior for consistency
     *
     * Note: For true cross-slot batching, phpredis would need pipeline() support
     * which is currently intentionally not implemented due to MOVED/ASK error complexity.
     *
     * @see https://github.com/phpredis/phpredis/blob/develop/cluster.md
     * @see https://github.com/phpredis/phpredis/issues/1910
     */
    private function executeCluster(array $values, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($values, $seconds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $seconds = max(1, $seconds);

            // MULTI/EXEC groups commands by node but does NOT pipeline them.
            // Commands are sent sequentially; exec() aggregates results from all nodes.
            $multi = $client->multi();

            foreach ($values as $key => $value) {
                // Use serialization helper to respect client configuration
                $serializedValue = $this->serialization->serialize($conn, $value);

                $multi->setex(
                    $prefix . $key,
                    $seconds,
                    $serializedValue
                );
            }

            $results = $multi->exec();

            // Check all results succeeded
            if (! is_array($results)) {
                return false;
            }

            foreach ($results as $result) {
                if ($result === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Execute using Lua script for better performance.
     *
     * The Lua script loops through all key-value pairs and executes SETEX
     * for each, reducing Redis command parsing overhead compared to
     * sending N individual SETEX commands.
     */
    private function executeUsingLua(array $values, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($values, $seconds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $seconds = max(1, $seconds);

            // Build keys and values arrays
            // KEYS: All the cache keys
            // ARGV[1]: TTL in seconds
            // ARGV[2..N+1]: Serialized values (matching order of KEYS)
            $keys = [];
            $args = [$seconds]; // First arg is TTL

            foreach ($values as $key => $value) {
                $keys[] = $prefix . $key;
                // Use serialization helper for Lua arguments
                $args[] = $this->serialization->serializeForLua($conn, $value);
            }

            // Combine keys and args for eval/evalSha
            // Format: [key1, key2, ..., ttl, val1, val2, ...]
            $evalArgs = array_merge($keys, $args);
            $numKeys = count($keys);

            $scriptHash = sha1(self::LUA_SCRIPT);
            $result = $client->evalSha($scriptHash, $evalArgs, $numKeys);

            // evalSha returns false if script not loaded (NOSCRIPT), fall back to eval
            if ($result === false) {
                $result = $client->eval(self::LUA_SCRIPT, $evalArgs, $numKeys);
            }

            return (bool) $result;
        });
    }
}
