<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache if it doesn't exist, with tags support.
 *
 * Uses Redis SET with NX flag for atomic "add if not exists" operation.
 * Only adds tag entries if the cache key was successfully created.
 *
 * Performance: Uses atomic HSETEX for tag entries after successful add.
 */
class Add
{
    /**
     * Create a new add with tags operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the add operation.
     *
     * @param string $key The cache key (without prefix)
     * @param mixed $value The value to store (will be serialized)
     * @param int $seconds TTL in seconds (must be > 0)
     * @param array<int, int|string> $tags Array of tag names (will be cast to strings)
     * @return bool True if item was added, false if it already exists
     */
    public function execute(string $key, mixed $value, int $seconds, array $tags): bool
    {
        // 1. Cluster Mode: Must use sequential commands
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $seconds, $tags);
        }

        // 2. Standard Mode: Use Lua for atomicity and performance
        return $this->executeUsingLua($key, $value, $seconds, $tags);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key, mixed $value, int $seconds, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tags) {
            $prefix = $this->context->prefix();

            // First try to add the key with NX flag
            $added = $connection->set(
                $prefix . $key,
                $this->serialization->serialize($connection, $value),
                ['EX' => max(1, $seconds), 'NX']
            );

            if (! $added) {
                return false;
            }

            // If successfully added, add to tags
            // Note: RedisCluster does not support pipeline(), so we execute sequentially.
            // This means we lose atomicity for the tag updates, but that's the trade-off for clusters.

            // Store reverse index of tags for this key
            $tagsKey = $this->context->reverseIndexKey($key);

            if (! empty($tags)) {
                // Use multi() for reverse index updates (same slot)
                $multi = $connection->multi();
                $multi->sadd($tagsKey, ...$tags);
                $multi->expire($tagsKey, max(1, $seconds));
                $multi->exec();
            }

            // Add to tags with field expiration (using HSETEX for atomic operation)
            // And update the Tag Registry
            $registryKey = $this->context->registryKey();
            $expiry = time() + $seconds;

            // 1. Update Tag Hashes (Cross-slot, must be sequential)
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $connection->hsetex($this->context->tagHashKey($tag), [$key => StoreContext::TAG_FIELD_VALUE], ['EX' => $seconds]);
            }

            // 2. Update Registry (Same slot, single command optimization)
            if (! empty($tags)) {
                $zaddArgs = [];

                foreach ($tags as $tag) {
                    $zaddArgs[] = $expiry;
                    $zaddArgs[] = (string) $tag;
                }

                // Update Registry: ZADD with GT (Greater Than) to only extend expiry
                $connection->zadd($registryKey, ['GT'], ...$zaddArgs);
            }

            return true;
        });
    }

    /**
     * Execute using Lua script for better performance.
     */
    private function executeUsingLua(string $key, mixed $value, int $seconds, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tags) {
            $prefix = $this->context->prefix();

            $keys = [
                $prefix . $key,                        // KEYS[1]
                $this->context->reverseIndexKey($key), // KEYS[2]
            ];

            $args = [
                $this->serialization->serializeForLua($connection, $value), // ARGV[1]
                max(1, $seconds),                            // ARGV[2]
                $this->context->fullTagPrefix(),             // ARGV[3]
                $this->context->fullRegistryKey(),           // ARGV[4]
                time(),                                      // ARGV[5]
                $key,                                        // ARGV[6]
                $this->context->tagHashSuffix(),             // ARGV[7]
                ...$tags,                                    // ARGV[8...]
            ];

            $result = $connection->evalWithShaCache($this->addWithTagsScript(), $keys, $args);

            return (bool) $result;
        });
    }

    /**
     * Get the Lua script for adding a value if it doesn't exist, with tag tracking.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key (tracks which tags this key belongs to)
     * ARGV[1] - Serialized value
     * ARGV[2] - TTL in seconds
     * ARGV[3] - Tag prefix for building tag hash keys
     * ARGV[4] - Tag registry key
     * ARGV[5] - Current timestamp
     * ARGV[6] - Raw key (without prefix, for hash field name)
     * ARGV[7] - Tag hash suffix (":entries")
     * ARGV[8...] - Tag names
     */
    protected function addWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local val = ARGV[1]
            local ttl = ARGV[2]
            local tagPrefix = ARGV[3]
            local registryKey = ARGV[4]
            local now = ARGV[5]
            local rawKey = ARGV[6]
            local tagHashSuffix = ARGV[7]
            local expiry = now + ttl

            -- 1. Try to add key (SET NX)
            -- redis.call returns a table/object for OK, or false/nil
            local added = redis.call('SET', key, val, 'EX', ttl, 'NX')

            if not added then
                return false
            end

            -- 2. Add to Tags Reverse Index
            local newTagsList = {}
            for i = 8, #ARGV do
                table.insert(newTagsList, ARGV[i])
            end

            if #newTagsList > 0 then
                redis.call('SADD', tagsKey, unpack(newTagsList))
                redis.call('EXPIRE', tagsKey, ttl)
            end

            -- 3. Add to Tag Hashes & Registry
            for _, tag in ipairs(newTagsList) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                -- Use HSET + HEXPIRE instead of HSETEX to avoid potential Lua argument issues
                redis.call('HSET', tagHash, rawKey, '1')
                redis.call('HEXPIRE', tagHash, ttl, 'FIELDS', 1, rawKey)
                redis.call('ZADD', registryKey, 'GT', expiry, tag)
            end

            return true
            LUA;
    }
}
