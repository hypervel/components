<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Retrieve an item from the cache by key.
 */
class Get
{
    /**
     * Create a new get operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the get operation.
     *
     * @param string $key The cache key (without prefix)
     * @return mixed The cached value, or null if not found or on error
     */
    public function execute(string $key): mixed
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key) {
            $value = $connection->get($this->context->prefix() . $key);

            return $this->serialization->unserialize($connection, $value);
        });
    }
}
