<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Remove an item from the cache along with its tag membership.
 *
 * A bare DEL would leave the key listed in its tag hashes; a later tag
 * flush would then delete an unrelated new value written at the reused
 * key. The registry is not touched because removing one key does not
 * empty a tag; registry hygiene belongs to pruning.
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
        if ($this->context->isCluster()) {
            return $this->executeCluster($key);
        }

        return $this->executeUsingLua($key);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key) {
            $tagsKey = $this->context->reverseIndexKey($key);
            $tags = $connection->smembers($tagsKey);

            foreach ($tags as $tag) {
                $connection->hdel($this->context->tagHashKey((string) $tag), $key);
            }

            if (! empty($tags)) {
                $connection->del($tagsKey);
            }

            return (bool) $connection->del($this->context->prefix() . $key);
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key) {
            $keys = [
                $this->context->prefix() . $key,
                $this->context->reverseIndexKey($key),
            ];

            $args = [
                $this->context->fullTagPrefix(),
                $key,
                $this->context->tagHashSuffix(),
            ];

            return (bool) $connection->evalWithShaCache($this->forgetWithTagsScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for deleting a value and its tag membership.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key
     * ARGV[1] - Tag prefix for building tag hash keys
     * ARGV[2] - Raw key (without prefix, for hash field name)
     * ARGV[3] - Tag hash suffix (":entries")
     */
    protected function forgetWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local tagPrefix = ARGV[1]
            local rawKey = ARGV[2]
            local tagHashSuffix = ARGV[3]

            local tags = redis.call('SMEMBERS', tagsKey)

            for _, tag in ipairs(tags) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                redis.call('HDEL', tagHash, rawKey)
            end

            if #tags > 0 then
                redis.call('DEL', tagsKey)
            end

            return redis.call('DEL', key)
            LUA;
    }
}
