<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;
use Redis;

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
    ) {
    }

    /**
     * Execute the forever operation.
     */
    public function execute(string $key, mixed $value): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value) {
            return (bool) $connection->set(
                $this->context->prefix() . $key,
                $this->serialization->serialize($connection, $value)
            );
        });
    }

    /**
     * Store multiple items in the cache indefinitely in one pipeline.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, bool>
     */
    public function executeMany(array $values): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($values): array {
            $prefix = $this->context->prefix();
            $entries = [];

            foreach ($values as $key => $value) {
                $entries[$prefix . $key] = $this->serialization->serialize($connection, $value);
            }

            $results = $this->context->pipeline($connection, function (Redis $pipeline) use ($entries): void {
                foreach ($entries as $key => $value) {
                    $pipeline->set((string) $key, $value);
                }
            });

            // Writes that joined the caller's queue succeed, as a queued single write does.
            return array_combine(array_keys($values), is_array($results)
                ? array_map(boolval(...), $results)
                : array_fill(0, count($values), $results === null));
        });
    }
}
