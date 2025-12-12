<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Retrieve multiple items from the cache by key.
 */
class Many
{
    /**
     * Create a new many operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {}

    /**
     * Execute the many (mget) operation.
     *
     * @param array<int, string> $keys The cache keys to retrieve
     * @return array<string, mixed> Key-value pairs, with null for missing keys
     */
    public function execute(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        return $this->context->withConnection(function (RedisConnection $conn) use ($keys) {
            $prefix = $this->context->prefix();

            $prefixedKeys = array_map(
                fn (string $key): string => $prefix . $key,
                $keys
            );

            $values = $conn->mget($prefixedKeys);
            $results = [];

            foreach ($values as $index => $value) {
                $results[$keys[$index]] = $this->serialization->unserialize($conn, $value);
            }

            return $results;
        });
    }
}
