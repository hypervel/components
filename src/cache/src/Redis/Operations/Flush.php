<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Remove all items from the cache (flush the database).
 */
class Flush
{
    /**
     * Create a new flush operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the flush operation.
     *
     * Warning: This removes ALL keys from the Redis database, not just cache keys.
     */
    public function execute(): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) {
            $connection->flushdb();

            return true;
        });
    }
}
