<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache with tags support.
 *
 * This operation creates:
 * 1. The cache key with TTL
 * 2. A reverse index SET tracking which tags this key belongs to
 * 3. Hash field entries in each tag's hash with expiration using HSETEX
 *
 * The reverse index is critical for proper cleanup during flush operations.
 * Without it, flushing one tag would leave orphaned fields in other tag hashes.
 *
 * Performance: Uses atomic HSETEX command (Redis 8.0+) for 30-40% reduction
 * in Redis commands compared to HSET + HEXPIRE pattern.
 *
 * Memory overhead: ~60 bytes per cached item (reverse index SET)
 * Command count: 4 base commands + (1 x N tags) with HSETEX optimization
 */
class Put
{
    /**
     * Create a new put with tags operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the put operation.
     *
     * @param string $key The cache key (without prefix)
     * @param mixed $value The value to store (will be serialized)
     * @param int $seconds TTL in seconds (must be > 0)
     * @param array<int, int|string> $tags Array of tag names (will be cast to strings)
     * @return bool True if successful, false on failure
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
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds, $tags) {
            $client = $conn->client();
            $prefix = $this->context->prefix();

            // Get old tags to handle replacement correctly (remove from old, add to new)
            $tagsKey = $this->context->reverseIndexKey($key);
            $oldTags = $client->smembers($tagsKey);

            // Store the actual cache value
            $client->setex(
                $prefix . $key,
                max(1, $seconds),
                $this->serialization->serialize($conn, $value)
            );

            // Store reverse index of tags for this key
            // Use multi() as these keys are in the same slot
            $multi = $client->multi();
            $multi->del($tagsKey); // Clear old tags

            if (! empty($tags)) {
                $multi->sadd($tagsKey, ...$tags);
                $multi->expire($tagsKey, max(1, $seconds));
            }

            $multi->exec();

            // Remove item from tags it no longer belongs to
            $tagsToRemove = array_diff($oldTags, $tags);

            foreach ($tagsToRemove as $tag) {
                $tag = (string) $tag;
                $client->hdel($this->context->tagHashKey($tag), $key);
            }

            // Add to each tag's hash with expiration (using HSETEX for atomic operation)
            // And update the Tag Registry
            $registryKey = $this->context->registryKey();
            $expiry = time() + $seconds;

            // 1. Update Tag Hashes (Cross-slot, must be sequential)
            foreach ($tags as $tag) {
                $tag = (string) $tag;

                // Use HSETEX to set field and expiration atomically in one command
                $client->hsetex(
                    $this->context->tagHashKey($tag),
                    [$key => StoreContext::TAG_FIELD_VALUE],
                    ['EX' => $seconds]
                );
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

            return true;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, mixed $value, int $seconds, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds, $tags) {
            $prefix = $this->context->prefix();

            $keys = [
                $prefix . $key,                        // KEYS[1]
                $this->context->reverseIndexKey($key), // KEYS[2]
            ];

            $args = [
                $this->serialization->serializeForLua($conn, $value), // ARGV[1]
                max(1, $seconds),                            // ARGV[2]
                $this->context->fullTagPrefix(),             // ARGV[3]
                $this->context->fullRegistryKey(),           // ARGV[4]
                time(),                                      // ARGV[5]
                $key,                                        // ARGV[6]
                $this->context->tagHashSuffix(),             // ARGV[7]
                ...$tags,                                    // ARGV[8...]
            ];

            $conn->evalWithShaCache($this->storeWithTagsScript(), $keys, $args);

            return true;
        });
    }

    /**
     * Get the Lua script for storing a value with tag tracking.
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
    protected function storeWithTagsScript(): string
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

            -- 1. Set Cache
            redis.call('SETEX', key, ttl, val)

            -- 2. Get Old Tags
            local oldTags = redis.call('SMEMBERS', tagsKey)
            local newTagsMap = {}
            local newTagsList = {}

            -- Parse new tags
            for i = 8, #ARGV do
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

            -- 4. Update Tags Key
            redis.call('DEL', tagsKey)
            if #newTagsList > 0 then
                redis.call('SADD', tagsKey, unpack(newTagsList))
                redis.call('EXPIRE', tagsKey, ttl)
            end

            -- 5. Add to New Tags & Registry
            for _, tag in ipairs(newTagsList) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                -- Use HSETEX for atomic field creation and expiration (Redis 8.0+)
                redis.call('HSETEX', tagHash, 'EX', ttl, 'FIELDS', 1, rawKey, '1')
                redis.call('ZADD', registryKey, 'GT', expiry, tag)
            end

            return true
            LUA;
    }
}
