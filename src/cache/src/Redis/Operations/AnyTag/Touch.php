<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Adjust the expiration time of a cached item and its tag metadata.
 *
 * Any-mode tag hash fields and reverse indexes carry their own TTLs, and
 * flush treats them as authoritative membership. A bare EXPIRE on the key
 * would let the key outlive its tag metadata, making a later tag flush
 * miss a still-live key.
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
     */
    public function execute(string $key, int $seconds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $seconds);
        }

        return $this->executeUsingLua($key, $seconds);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds) {
            $seconds = max(1, $seconds);

            if (! $connection->expire($this->context->prefix() . $key, $seconds)) {
                return false;
            }

            $tagsKey = $this->context->reverseIndexKey($key);
            $tags = $connection->smembers($tagsKey);

            if (empty($tags)) {
                return true;
            }

            $connection->expire($tagsKey, $seconds);

            foreach ($tags as $tag) {
                $connection->hexpire($this->context->tagHashKey((string) $tag), $seconds, [$key]);
            }

            $expiry = time() + $seconds;
            $zaddArgs = [];

            foreach ($tags as $tag) {
                $zaddArgs[] = $expiry;
                $zaddArgs[] = (string) $tag;
            }

            $connection->zadd($this->context->registryKey(), ['GT'], ...$zaddArgs);

            return true;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds) {
            $keys = [
                $this->context->prefix() . $key,
                $this->context->reverseIndexKey($key),
            ];

            $args = [
                max(1, $seconds),
                $this->context->fullTagPrefix(),
                $this->context->fullRegistryKey(),
                time(),
                $key,
                $this->context->tagHashSuffix(),
            ];

            return (bool) $connection->evalWithShaCache($this->touchWithTagsScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for touching a value and its tag metadata.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key
     * ARGV[1] - TTL in seconds
     * ARGV[2] - Tag prefix for building tag hash keys
     * ARGV[3] - Tag registry key
     * ARGV[4] - Current timestamp
     * ARGV[5] - Raw key (without prefix, for hash field name)
     * ARGV[6] - Tag hash suffix (":entries")
     */
    protected function touchWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local ttl = tonumber(ARGV[1])
            local tagPrefix = ARGV[2]
            local registryKey = ARGV[3]
            local now = tonumber(ARGV[4])
            local rawKey = ARGV[5]
            local tagHashSuffix = ARGV[6]

            if redis.call('EXPIRE', key, ttl) == 0 then
                return false
            end

            local tags = redis.call('SMEMBERS', tagsKey)

            if #tags == 0 then
                return true
            end

            redis.call('EXPIRE', tagsKey, ttl)

            local expiry = now + ttl

            for _, tag in ipairs(tags) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                redis.call('HEXPIRE', tagHash, ttl, 'FIELDS', 1, rawKey)
                redis.call('ZADD', registryKey, 'GT', expiry, tag)
            end

            return true
            LUA;
    }
}
