<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Closure;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Get an item from the cache, or execute a callback and store the result.
 *
 * This operation is optimized to use a single connection for both the GET
 * and SET operations, avoiding the overhead of acquiring/releasing a
 * connection from the pool twice for cache misses.
 */
class Remember
{
    /**
     * Create a new remember operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the remember operation.
     *
     * @param string $key The cache key (without prefix)
     * @param int $seconds TTL in seconds
     * @param Closure $callback The callback to execute on cache miss
     * @return mixed The cached or computed value
     */
    public function execute(string $key, int $seconds, Closure $callback): mixed
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $seconds, $callback) {
            $prefixedKey = $this->context->prefix() . $key;

            // Try to get the cached value
            $value = $conn->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return $this->serialization->unserialize($conn, $value);
            }

            // Cache miss - execute callback and store result
            $value = $callback();

            $conn->setex(
                $prefixedKey,
                max(1, $seconds),
                $this->serialization->serialize($conn, $value)
            );

            return $value;
        });
    }
}
