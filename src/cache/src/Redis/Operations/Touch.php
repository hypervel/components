<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Adjust the expiration time of a cached item.
 */
class Touch
{
    /**
     * Create a new touch operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the touch (expire) operation.
     */
    public function execute(string $key, int $seconds): bool
    {
        return $this->context->withConnection(
            fn (RedisConnection $connection) => (bool) $connection->expire(
                $this->context->prefix() . $key,
                max(1, $seconds)
            )
        );
    }
}
