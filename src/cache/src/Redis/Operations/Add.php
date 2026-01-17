<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache if it doesn't exist (non-tagged).
 *
 * Uses Redis SET with NX (only set if Not eXists) and EX (expiration) flags
 * for atomic "add if not exists" semantics without requiring Lua scripts.
 */
class Add
{
    /**
     * Create a new add operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the add operation.
     *
     * @param string $key The cache key (without prefix)
     * @param mixed $value The value to store (will be serialized)
     * @param int $seconds TTL in seconds (must be > 0)
     * @return bool True if item was added, false if it already exists or on failure
     */
    public function execute(string $key, mixed $value, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds) {
            // SET key value EX seconds NX
            // - EX: Set expiration in seconds
            // - NX: Only set if key does Not eXist
            // Returns OK if set, null/false if key already exists
            $result = $conn->set(
                $this->context->prefix() . $key,
                $this->serialization->serialize($conn, $value),
                ['EX' => max(1, $seconds), 'NX']
            );

            return (bool) $result;
        });
    }
}
