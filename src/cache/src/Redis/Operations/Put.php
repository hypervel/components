<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache for a given number of seconds.
 */
class Put
{
    /**
     * Create a new put operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the put operation.
     */
    public function execute(string $key, mixed $value, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds) {
            return (bool) $conn->setex(
                $this->context->prefix() . $key,
                max(1, $seconds),
                $this->serialization->serialize($conn, $value)
            );
        });
    }
}
