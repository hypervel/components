<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache indefinitely (no expiration).
 */
class Forever
{
    /**
     * Create a new forever operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {}

    /**
     * Execute the forever operation.
     */
    public function execute(string $key, mixed $value): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value) {
            return (bool) $conn->set(
                $this->context->prefix() . $key,
                $this->serialization->serialize($conn, $value)
            );
        });
    }
}
