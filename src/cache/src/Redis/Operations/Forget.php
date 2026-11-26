<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;
use Redis;

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
        return $this->context->withConnection(function (RedisConnection $connection) use ($key) {
            return (bool) $connection->del($this->context->prefix() . $key);
        });
    }

    /**
     * Remove multiple items from the cache in one pipeline.
     *
     * @param list<string> $keys
     * @return list<bool>
     */
    public function executeMany(array $keys): array
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($keys): array {
            $prefix = $this->context->prefix();

            // REMOVED: Laravel retries the pipeline after a lost connection, but a replayed DEL can remove a value written again in between.
            $results = $this->context->pipeline($connection, function (Redis $pipeline) use ($keys, $prefix): void {
                foreach ($keys as $key) {
                    $pipeline->del($prefix . $key);
                }
            });

            // Deletes that joined the caller's queue succeed, as a queued single delete does.
            return is_array($results)
                ? array_map(boolval(...), $results)
                : array_fill(0, count($keys), $results === null);
        });
    }
}
