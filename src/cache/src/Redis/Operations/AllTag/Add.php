<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache if it doesn't exist, with all tag tracking.
 *
 * Publish tag entries only when SET NX creates the value. A failed add must
 * not shorten an existing entry's lifetime and make pruning hide a live value.
 */
class Add
{
    /**
     * Create a new add operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the add operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param mixed $value The value to store
     * @param int $seconds TTL in seconds; values below one are stored for one second
     * @param array<string> $tagIds Array of tag identifiers
     * @return bool True if the key was added; false if it already existed or a write failed
     */
    public function execute(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        $seconds = max(1, $seconds);

        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $seconds, $tagIds);
        }

        return $this->executeUsingLua($key, $value, $seconds, $tagIds);
    }

    /**
     * Execute atomically for standard Redis.
     */
    private function executeUsingLua(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tagIds) {
            $prefix = $this->context->prefix();

            if ($tagIds === []) {
                return (bool) $connection->set(
                    $prefix . $key,
                    $this->serialization->serialize($connection, $value),
                    ['EX' => $seconds, 'NX']
                );
            }

            return (bool) $connection->evalWithShaCache(
                $this->addWithTagsScript(),
                [$prefix . $key, ...array_map(fn (string $tagId): string => $prefix . $tagId, $tagIds)],
                [$this->serialization->serializeForLua($connection, $value), $seconds, $this->context->expirationScore($seconds), $key],
            );
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Uses SET NX EX for atomic add, then sequential ZADD commands because
     * tags may be in different slots.
     */
    private function executeCluster(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tagIds) {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);

            $result = $connection->set(
                $prefix . $key,
                $this->serialization->serialize($connection, $value),
                ['EX' => $seconds, 'NX']
            );

            if (! $result) {
                return false;
            }

            $membershipsSucceeded = true;

            foreach ($tagIds as $tagId) {
                if ($connection->zadd($prefix . $tagId, $score, $key) === false) {
                    $membershipsSucceeded = false;
                }
            }

            return $membershipsSucceeded;
        });
    }

    /**
     * Register memberships only after creating the value.
     */
    protected function addWithTagsScript(): string
    {
        return <<<'LUA'
            local added = redis.pcall('SET', KEYS[1], ARGV[1], 'EX', ARGV[2], 'NX')

            if not added or (type(added) == 'table' and added.err) then
                return 0
            end

            local success = 1

            for i = 2, #KEYS do
                local result = redis.pcall('ZADD', KEYS[i], ARGV[3], ARGV[4])
                if type(result) == 'table' and result.err then
                    success = 0
                end
            end

            return success
            LUA;
    }
}
