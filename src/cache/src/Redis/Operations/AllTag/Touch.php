<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

use function Hypervel\Support\now;

/**
 * Adjust the expiration time of a tagged cache item and its tag entries.
 *
 * All-mode tag ZSET scores are the authoritative expiry used by stale
 * pruning. A bare EXPIRE on the item key would desynchronize the scores:
 * pruning would drop the entry while the key lives, and a later tag flush
 * would miss it.
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
     * Execute the touch operation.
     *
     * @param array<string> $tagIds Array of tag identifiers
     */
    public function execute(string $key, int $seconds, array $tagIds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $seconds, $tagIds);
        }

        return $this->executeUsingLua($key, $seconds, $tagIds);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds, $tagIds) {
            $prefix = $this->context->prefix();
            $seconds = max(1, $seconds);

            if (! $connection->expire($prefix . $key, $seconds)) {
                return false;
            }

            $score = now()->addSeconds($seconds)->getTimestamp();

            foreach ($tagIds as $tagId) {
                $connection->zadd($prefix . $tagId, $score, $key);
            }

            return true;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds, $tagIds) {
            $seconds = max(1, $seconds);

            // Static tag ZSET keys belong in KEYS so phpredis applies
            // OPT_PREFIX; ARGV-built keys are only for dynamic Lua paths.
            $keys = [
                $this->context->prefix() . $key,
                ...array_map(fn (string $tagId) => $this->context->prefix() . $tagId, $tagIds),
            ];

            $args = [
                $seconds,
                now()->addSeconds($seconds)->getTimestamp(),
                $key,
            ];

            return (bool) $connection->evalWithShaCache($this->touchTaggedScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for touching a tagged value and its ZSET scores.
     *
     * KEYS[1] - The cache key (prefixed, namespaced)
     * KEYS[2...] - Prefixed tag ZSET keys
     * ARGV[1] - TTL in seconds
     * ARGV[2] - New expiry score
     * ARGV[3] - Raw namespaced key (ZSET member)
     */
    protected function touchTaggedScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local ttl = tonumber(ARGV[1])
            local score = tonumber(ARGV[2])
            local member = ARGV[3]

            if redis.call('EXPIRE', key, ttl) == 0 then
                return false
            end

            for i = 2, #KEYS do
                redis.call('ZADD', KEYS[i], score, member)
            end

            return true
            LUA;
    }
}
