<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Closure;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Get an item from the cache, or execute a callback and store the result forever.
 *
 * This operation is optimized to use a single connection for both the GET
 * and SET operations, avoiding the overhead of acquiring/releasing a
 * connection from the pool twice for cache misses.
 *
 * Unlike Remember which uses SETEX with TTL, this uses SET without expiration.
 */
class RememberForever
{
    /**
     * Create a new remember forever operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the remember forever operation.
     *
     * @param string $key The cache key (without prefix)
     * @param Closure $callback The callback to execute on cache miss
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    public function execute(string $key, Closure $callback): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $callback) {
            $prefixedKey = $this->context->prefix() . $key;

            // Try to get the cached value
            $value = $connection->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($connection, $value), true];
            }

            // Cache miss - execute callback and store result forever (no TTL)
            $value = $callback();

            $connection->set(
                $prefixedKey,
                $this->serialization->serialize($connection, $value)
            );

            return [$value, false];
        });
    }
}
