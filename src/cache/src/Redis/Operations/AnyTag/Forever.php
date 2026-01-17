<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache indefinitely with tags support.
 *
 * Stores the cache key and tag hash fields WITHOUT expiration (TTL = -1).
 * Items must be manually deleted or flushed via tags.
 *
 * Optimization: Uses Lua script to perform set, tag cleanup (remove from old),
 * and tag addition (add to new) in a single network round trip (1 RTT).
 */
class Forever
{
    /**
     * Create a new forever with tags operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the forever operation.
     *
     * @param string $key The cache key (without prefix)
     * @param mixed $value The value to store (will be serialized)
     * @param array<int, int|string> $tags Array of tag names (will be cast to strings)
     * @return bool True if successful, false on failure
     */
    public function execute(string $key, mixed $value, array $tags): bool
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
    private function executeCluster(string $key, mixed $value, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tags) {
            $prefix = $this->context->prefix();

            // Get old tags to handle replacement correctly (remove from old, add to new)
            $tagsKey = $this->context->reverseIndexKey($key);
            $oldTags = $conn->smembers($tagsKey);

            // Store the actual cache value without expiration
            $conn->set(
                $prefix . $key,
                $this->serialization->serialize($conn, $value)
            );

            // Store reverse index of tags for this key
            // Use multi() as these keys are in the same slot
            $multi = $conn->multi();
            $multi->del($tagsKey);

            if (! empty($tags)) {
                $multi->sadd($tagsKey, ...$tags);
            }

            $multi->exec();

            // Remove item from tags it no longer belongs to
            $tagsToRemove = array_diff($oldTags, $tags);

            foreach ($tagsToRemove as $tag) {
                $tag = (string) $tag;
                $conn->hdel($this->context->tagHashKey($tag), $key);
            }

            // Calculate expiry for Registry (Year 9999)
            $expiry = StoreContext::MAX_EXPIRY;
            $registryKey = $this->context->registryKey();

            // 1. Add to each tag's hash without expiration (Cross-slot, sequential)
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $conn->hSet($this->context->tagHashKey($tag), $key, StoreContext::TAG_FIELD_VALUE);
                // No HEXPIRE for forever items
            }

            // 2. Update Registry (Same slot, single command optimization)
            if (! empty($tags)) {
                $zaddArgs = [];

                foreach ($tags as $tag) {
                    $zaddArgs[] = $expiry;
                    $zaddArgs[] = (string) $tag;
                }

                // Update Registry: ZADD with GT (Greater Than) to only extend expiry
                $conn->zadd($registryKey, ['GT'], ...$zaddArgs);
            }

            return true;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, mixed $value, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $tags) {
            $prefix = $this->context->prefix();

            $keys = [
                $prefix . $key,                        // KEYS[1]
                $this->context->reverseIndexKey($key), // KEYS[2]
            ];

            $args = [
                $this->serialization->serializeForLua($conn, $value), // ARGV[1]
                $this->context->fullTagPrefix(),             // ARGV[2]
                $this->context->fullRegistryKey(),           // ARGV[3]
                $key,                                        // ARGV[4]
                $this->context->tagHashSuffix(),             // ARGV[5]
                ...$tags,                                    // ARGV[6...]
            ];

            $conn->evalWithShaCache($this->storeForeverWithTagsScript(), $keys, $args);

            return true;
        });
    }

    /**
     * Get the Lua script for storing a value forever with tag tracking.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key (tracks which tags this key belongs to)
     * ARGV[1] - Serialized value
     * ARGV[2] - Tag prefix for building tag hash keys
     * ARGV[3] - Tag registry key
     * ARGV[4] - Raw key (without prefix, for hash field name)
     * ARGV[5] - Tag hash suffix (":entries")
     * ARGV[6...] - Tag names
     */
    protected function storeForeverWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local val = ARGV[1]
            local tagPrefix = ARGV[2]
            local registryKey = ARGV[3]
            local rawKey = ARGV[4]
            local tagHashSuffix = ARGV[5]

            -- 1. Set Value
            redis.call('SET', key, val)

            -- 2. Get Old Tags
            local oldTags = redis.call('SMEMBERS', tagsKey)
            local newTagsMap = {}
            local newTagsList = {}

            for i = 6, #ARGV do
                local tag = ARGV[i]
                newTagsMap[tag] = true
                table.insert(newTagsList, tag)
            end

            -- 3. Remove from Old Tags
            for _, tag in ipairs(oldTags) do
                if not newTagsMap[tag] then
                    local tagHash = tagPrefix .. tag .. tagHashSuffix
                    redis.call('HDEL', tagHash, rawKey)
                end
            end

            -- 4. Update Reverse Index
            redis.call('DEL', tagsKey)
            if #newTagsList > 0 then
                redis.call('SADD', tagsKey, unpack(newTagsList))
            end

            -- 5. Add to New Tags
            local expiry = 253402300799
            for _, tag in ipairs(newTagsList) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                redis.call('HSET', tagHash, rawKey, '1')
                redis.call('ZADD', registryKey, 'GT', expiry, tag)
            end

            return true
            LUA;
    }
}
