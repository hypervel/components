<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Closure;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Get an item from the cache, or execute a callback and store the result forever with tags.
 *
 * This operation is optimized to use a single connection for both the GET
 * and the tagged SET operations, avoiding the overhead of acquiring/releasing
 * a connection from the pool multiple times for cache misses.
 *
 * Unlike Remember which uses SETEX with TTL, this uses SET without expiration
 * and HSET without HEXPIRE for tag hash fields.
 */
class RememberForever
{
    /**
     * Create a new remember forever operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the remember forever operation with tags.
     *
     * @param string $key The cache key (without prefix)
     * @param Closure $callback The callback to execute on cache miss
     * @param array<int, int|string> $tags Array of tag names (will be cast to strings)
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    public function execute(string $key, Closure $callback, array $tags): array
    {
        // Cluster Mode: Must use sequential commands
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $callback, $tags);
        }

        // Standard Mode: Use Lua for atomicity and performance
        return $this->executeUsingLua($key, $callback, $tags);
    }

    /**
     * Execute for cluster using sequential commands.
     *
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    private function executeCluster(string $key, Closure $callback, array $tags): array
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $callback, $tags) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $prefixedKey = $prefix . $key;

            // Try to get the cached value
            $value = $client->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($conn, $value), true];
            }

            // Cache miss - execute callback
            $value = $callback();

            // Get old tags to handle replacement correctly (remove from old, add to new)
            $tagsKey = $this->context->reverseIndexKey($key);
            $oldTags = $client->smembers($tagsKey);

            // Store the actual cache value without expiration
            $client->set(
                $prefixedKey,
                $this->serialization->serialize($conn, $value)
            );

            // Store reverse index of tags for this key (no expiration for forever)
            // Use multi() as these keys are in the same slot
            $multi = $client->multi();
            $multi->del($tagsKey);

            if (! empty($tags)) {
                $multi->sadd($tagsKey, ...$tags);
            }

            $multi->exec();

            // Remove item from tags it no longer belongs to
            $tagsToRemove = array_diff($oldTags, $tags);

            foreach ($tagsToRemove as $tag) {
                $tag = (string) $tag;
                $client->hdel($this->context->tagHashKey($tag), $key);
            }

            // Calculate expiry for Registry (Year 9999)
            $expiry = StoreContext::MAX_EXPIRY;
            $registryKey = $this->context->registryKey();

            // 1. Add to each tag's hash without expiration (Cross-slot, sequential)
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $client->hSet($this->context->tagHashKey($tag), $key, StoreContext::TAG_FIELD_VALUE);
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
                $client->zadd($registryKey, ['GT'], ...$zaddArgs);
            }

            return [$value, false];
        });
    }

    /**
     * Execute using Lua script for performance.
     *
     * @return array{0: mixed, 1: bool} Tuple of [value, wasHit]
     */
    private function executeUsingLua(string $key, Closure $callback, array $tags): array
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $callback, $tags) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $prefixedKey = $prefix . $key;

            // Try to get the cached value first
            $value = $client->get($prefixedKey);

            if ($value !== false && $value !== null) {
                return [$this->serialization->unserialize($conn, $value), true];
            }

            // Cache miss - execute callback
            $value = $callback();

            // Now use Lua script to atomically store with tags (forever semantics)
            $script = <<<'LUA'
                local key = KEYS[1]
                local tagsKey = KEYS[2]
                local val = ARGV[1]
                local tagPrefix = ARGV[2]
                local registryKey = ARGV[3]
                local rawKey = ARGV[4]
                local tagHashSuffix = ARGV[5]

                -- 1. Set Value (no expiration)
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

                -- 4. Update Reverse Index (no expiration for forever)
                redis.call('DEL', tagsKey)
                if #newTagsList > 0 then
                    redis.call('SADD', tagsKey, unpack(newTagsList))
                end

                -- 5. Add to New Tags (HSET without HEXPIRE, registry with MAX_EXPIRY)
                local expiry = 253402300799
                for _, tag in ipairs(newTagsList) do
                    local tagHash = tagPrefix .. tag .. tagHashSuffix
                    redis.call('HSET', tagHash, rawKey, '1')
                    redis.call('ZADD', registryKey, 'GT', expiry, tag)
                end

                return true
LUA;

            $args = [
                $prefixedKey,                                // KEYS[1]
                $this->context->reverseIndexKey($key),       // KEYS[2]
                $this->serialization->serializeForLua($conn, $value), // ARGV[1]
                $this->context->fullTagPrefix(),             // ARGV[2]
                $this->context->fullRegistryKey(),           // ARGV[3]
                $key,                                        // ARGV[4]
                $this->context->tagHashSuffix(),             // ARGV[5]
                ...$tags,                                     // ARGV[6...]
            ];

            $scriptHash = sha1($script);
            $result = $client->evalSha($scriptHash, $args, 2);

            // evalSha returns false if script not loaded (NOSCRIPT), fall back to eval
            if ($result === false) {
                $client->eval($script, $args, 2);
            }

            return [$value, false];
        });
    }
}
