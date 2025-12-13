<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Remove an item from the cache.
 */
class Forget
{
    /**
     * Create a new forget operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the forget (delete) operation.
     */
    public function execute(string $key): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key) {
            return (bool) $conn->del($this->context->prefix() . $key);
        });
    }
}
