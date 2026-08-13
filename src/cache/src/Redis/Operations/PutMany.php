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
 * - Cluster mode: Sequential SETEX commands over one held connection
 */
class PutMany
{
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

        // Cluster mode: Keys may hash to different slots.
        if ($this->context->isCluster()) {
            return $this->executeCluster($values, $seconds);
        }

        // Standard mode: Use Lua script for efficiency
        return $this->executeUsingLua($values, $seconds);
    }

    /**
     * Execute for Redis Cluster using individual SETEX commands.
     */
    private function executeCluster(array $values, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($values, $seconds) {
            $prefix = $this->context->prefix();
            $seconds = max(1, $seconds);
            $successful = true;

            foreach ($values as $key => $value) {
                $serializedValue = $this->serialization->serialize($connection, $value);
                $result = $connection->setex(
                    $prefix . $key,
                    $seconds,
                    $serializedValue
                );

                $successful = $result !== false && $successful;
            }

            return $successful;
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
        return $this->context->withConnection(function (RedisConnection $connection) use ($values, $seconds) {
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
                $args[] = $this->serialization->serializeForLua($connection, $value);
            }

            $result = $connection->evalWithShaCache($this->setMultipleKeysScript(), $keys, $args);

            return (bool) $result;
        });
    }

    /**
     * Get the Lua script for setting multiple keys with the same TTL.
     *
     * KEYS[1..N] - The cache keys to set
     * ARGV[1] - TTL in seconds
     * ARGV[2..N+1] - Serialized values (matching order of KEYS)
     */
    protected function setMultipleKeysScript(): string
    {
        return <<<'LUA'
            local ttl = ARGV[1]
            local numKeys = #KEYS
            for i = 1, numKeys do
                redis.call('SETEX', KEYS[i], ttl, ARGV[i + 1])
            end
            return true
            LUA;
    }
}
