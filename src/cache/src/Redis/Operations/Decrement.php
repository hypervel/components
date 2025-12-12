<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Decrement the value of an item in the cache.
 */
class Decrement
{
    /**
     * Create a new decrement operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the decrement operation.
     */
    public function execute(string $key, int $value = 1): int
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value) {
            return $conn->decrBy($this->context->prefix() . $key, $value);
        });
    }
}
