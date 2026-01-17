<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Decrement the value of an item in the cache with tags support.
 *
 * Decrements the numeric value and adds/updates tag entries. If the key
 * doesn't exist, creates it with negative of decrement value (no initial TTL).
 *
 * Optimization: Uses Lua script to perform decrement, TTL check, and tag updates
 * in a single network round trip (1 RTT).
 */
class Decrement
{
    /**
     * Create a new decrement with tags operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the decrement operation.
     *
     * @param string $key The cache key (without prefix)
     * @param int $value The amount to decrement by
     * @param array<int, int|string> $tags Array of tag names (will be cast to strings)
     * @return false|int The new value after decrementing, or false on failure
     */
    public function execute(string $key, int $value, array $tags): int|bool
    {
        // 1. Cluster Mode: Must use sequential commands
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $tags);
        }

        // 2. Standard Mode: Use Lua for atomicity and performance
        return $this->executeUsingLua($key, $value, $tags);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key, int $value, array $tags): int|bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tags) {
            $prefix = $this->context->prefix();

            // 1. Decrement and Get TTL (Same slot, so we can use multi)
            $multi = $conn->multi();
            $multi->decrBy($prefix . $key, $value);
            $multi->ttl($prefix . $key);
            [$newValue, $ttl] = $multi->exec();

            $tagsKey = $this->context->reverseIndexKey($key);
            $oldTags = $conn->smembers($tagsKey);

            // Add to tags with expiration if the key has TTL
            if (! empty($tags)) {
                // 2. Update Reverse Index (Same slot, so we can use multi)
                $multi = $conn->multi();
                $multi->del($tagsKey);
                $multi->sadd($tagsKey, ...$tags);

                if ($ttl > 0) {
                    $multi->expire($tagsKey, $ttl);
                }

                $multi->exec();

                // Remove item from tags it no longer belongs to
                $tagsToRemove = array_diff($oldTags, $tags);

                foreach ($tagsToRemove as $tag) {
                    $tag = (string) $tag;
                    $conn->hdel($this->context->tagHashKey($tag), $key);
                }

                // Calculate expiry for Registry
                $expiry = ($ttl > 0) ? (time() + $ttl) : StoreContext::MAX_EXPIRY;
                $registryKey = $this->context->registryKey();

                // 3. Update Tag Hashes (Cross-slot, must be sequential)
                foreach ($tags as $tag) {
                    $tag = (string) $tag;
                    $tagHashKey = $this->context->tagHashKey($tag);

                    if ($ttl > 0) {
                        // Use HSETEX for atomic operation
                        $conn->hsetex($tagHashKey, [$key => StoreContext::TAG_FIELD_VALUE], ['EX' => $ttl]);
                    } else {
                        $conn->hSet($tagHashKey, $key, StoreContext::TAG_FIELD_VALUE);
                    }
                }

                // 4. Update Registry (Same slot, single command optimization)
                $zaddArgs = [];

                foreach ($tags as $tag) {
                    $zaddArgs[] = $expiry;
                    $zaddArgs[] = (string) $tag;
                }

                $conn->zadd($registryKey, ['GT'], ...$zaddArgs);
            }

            return $newValue;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, int $value, array $tags): int|bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tags) {
            $prefix = $this->context->prefix();

            $keys = [
                $prefix . $key,                        // KEYS[1]
                $this->context->reverseIndexKey($key), // KEYS[2]
            ];

            $args = [
                $value,                            // ARGV[1]
                $this->context->fullTagPrefix(),   // ARGV[2]
                $this->context->fullRegistryKey(), // ARGV[3]
                time(),                            // ARGV[4]
                $key,                              // ARGV[5]
                $this->context->tagHashSuffix(),   // ARGV[6]
                ...$tags,                          // ARGV[7...]
            ];

            return $conn->evalWithShaCache($this->decrementWithTagsScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for decrementing a value with tag tracking.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key (tracks which tags this key belongs to)
     * ARGV[1] - Decrement amount
     * ARGV[2] - Tag prefix for building tag hash keys
     * ARGV[3] - Tag registry key
     * ARGV[4] - Current timestamp
     * ARGV[5] - Raw key (without prefix, for hash field name)
     * ARGV[6] - Tag hash suffix (":entries")
     * ARGV[7...] - Tag names
     */
    protected function decrementWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local val = tonumber(ARGV[1])
            local tagPrefix = ARGV[2]
            local registryKey = ARGV[3]
            local now = ARGV[4]
            local rawKey = ARGV[5]
            local tagHashSuffix = ARGV[6]

            -- 1. Decrement
            local newValue = redis.call('DECRBY', key, val)

            -- 2. Get TTL
            local ttl = redis.call('TTL', key)
            local expiry = 253402300799 -- Default forever
            if ttl > 0 then
                expiry = now + ttl
            end

            -- 3. Get Old Tags
            local oldTags = redis.call('SMEMBERS', tagsKey)
            local newTagsMap = {}
            local newTagsList = {}

            for i = 7, #ARGV do
                local tag = ARGV[i]
                newTagsMap[tag] = true
                table.insert(newTagsList, tag)
            end

            -- 4. Remove from Old Tags
            for _, tag in ipairs(oldTags) do
                if not newTagsMap[tag] then
                    local tagHash = tagPrefix .. tag .. tagHashSuffix
                    redis.call('HDEL', tagHash, rawKey)
                end
            end

            -- 5. Update Reverse Index
            redis.call('DEL', tagsKey)
            if #newTagsList > 0 then
                redis.call('SADD', tagsKey, unpack(newTagsList))
                if ttl > 0 then
                    redis.call('EXPIRE', tagsKey, ttl)
                end
            end

            -- 6. Update Tag Hashes
            for _, tag in ipairs(newTagsList) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                if ttl > 0 then
                    redis.call('HSETEX', tagHash, 'EX', ttl, 'FIELDS', 1, rawKey, '1')
                else
                    redis.call('HSET', tagHash, rawKey, '1')
                end
                redis.call('ZADD', registryKey, 'GT', expiry, tag)
            end

            return newValue
            LUA;
    }
}
