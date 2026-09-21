<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Increment a value in the cache with all tag tracking.
 *
 * Combines the ZADD NX operations for tag tracking with INCRBY
 * in a single connection checkout for efficiency.
 *
 * Uses ZADD NX (only add if not exists) to avoid overwriting existing
 * tag entries that may have TTL information.
 */
class Increment
{
    /**
     * Score for increment operations (no TTL - persists until deleted).
     */
    private const int FOREVER_SCORE = -1;

    /**
     * Create a new increment operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the increment operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param int $value The value to increment by
     * @param array<string> $tagIds Array of tag identifiers
     * @return false|int The new value after incrementing, or false on failure
     */
    public function execute(string $key, int $value, array $tagIds): int|false
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $tagIds);
        }

        return $this->executeUsingLua($key, $value, $tagIds);
    }

    /**
     * Execute the counter and membership writes atomically for standard Redis.
     */
    private function executeUsingLua(string $key, int $value, array $tagIds): int|false
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $tagIds): int|false {
            $prefix = $this->context->prefix();

            $result = $connection->evalWithShaCache(
                $this->incrementWithTagsScript(),
                [$prefix . $key, ...array_map(fn (string $tagId): string => $prefix . $tagId, $tagIds)],
                [$value, self::FOREVER_SCORE, $key],
            );

            return $result === false ? false : (int) $result;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     */
    private function executeCluster(string $key, int $value, array $tagIds): int|false
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $tagIds): int|false {
            $prefix = $this->context->prefix();

            // Publish tag memberships only after Redis confirms the counter write.
            $newValue = $connection->incrBy($prefix . $key, $value);

            if (! is_int($newValue)) {
                return false;
            }

            // ZADD NX to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $connection->zadd($prefix . $tagId, ['NX'], self::FOREVER_SCORE, $key);
            }

            return $newValue;
        });
    }

    /**
     * Register memberships only after incrementing the counter.
     */
    protected function incrementWithTagsScript(): string
    {
        return <<<'LUA'
            local result = redis.pcall('INCRBY', KEYS[1], ARGV[1])

            if type(result) == 'table' and result.err then
                return false
            end

            for i = 2, #KEYS do
                redis.pcall('ZADD', KEYS[i], 'NX', ARGV[2], ARGV[3])
            end

            -- GET preserves the full Redis integer instead of rounding through Lua's number type.
            return redis.call('GET', KEYS[1])
            LUA;
    }
}
