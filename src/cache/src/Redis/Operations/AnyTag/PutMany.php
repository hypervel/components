<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store multiple items in the cache with tags support.
 *
 * Efficiently stores many items in a single pipelined operation,
 * handling tag assignment and cleanup for all items.
 */
class PutMany
{
    private const CHUNK_SIZE = 1000;

    /**
     * Create a new put many with tags operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the putMany operation.
     *
     * @param array<string, mixed> $values Array of key => value pairs
     * @param int $seconds TTL in seconds
     * @param array<int, int|string> $tags Array of tag names
     * @return bool True if successful, false on failure
     */
    public function execute(array $values, int $seconds, array $tags): bool
    {
        if (empty($values)) {
            return true;
        }

        // 1. Cluster Mode: Must use sequential commands
        if ($this->context->isCluster()) {
            return $this->executeCluster($values, $seconds, $tags);
        }

        // 2. Standard Mode: Use Pipeline
        return $this->executeUsingPipeline($values, $seconds, $tags);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(array $values, int $seconds, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($values, $seconds, $tags) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $registryKey = $this->context->registryKey();
            $expiry = time() + $seconds;
            $ttl = max(1, $seconds);

            foreach (array_chunk($values, self::CHUNK_SIZE, true) as $chunk) {
                // Step 1: Retrieve old tags for all keys in the chunk
                $oldTagsResults = [];

                foreach ($chunk as $key => $value) {
                    $oldTagsResults[] = $client->smembers($this->context->reverseIndexKey($key));
                }

                // Step 2: Prepare updates
                $keysByNewTag = [];
                $keysToRemoveByTag = [];

                $i = 0;

                foreach ($chunk as $key => $value) {
                    $oldTags = $oldTagsResults[$i] ?? [];
                    ++$i;

                    // Calculate tags to remove (Old Tags - New Tags)
                    $tagsToRemove = array_diff($oldTags, $tags);

                    foreach ($tagsToRemove as $tag) {
                        $keysToRemoveByTag[$tag][] = $key;
                    }

                    // 1. Store the actual cache value
                    $client->setex(
                        $prefix . $key,
                        $ttl,
                        $this->serialization->serialize($conn, $value)
                    );

                    // 2. Store reverse index of tags for this key
                    $tagsKey = $this->context->reverseIndexKey($key);

                    // Use multi() for reverse index updates (same slot)
                    $multi = $client->multi();
                    $multi->del($tagsKey); // Clear old tags

                    if (! empty($tags)) {
                        $multi->sadd($tagsKey, ...$tags);
                        $multi->expire($tagsKey, $ttl);
                    }

                    $multi->exec();

                    // Collect keys for batch tag update (New Tags)
                    foreach ($tags as $tag) {
                        $keysByNewTag[$tag][] = $key;
                    }
                }

                // 3. Batch remove from old tags
                foreach ($keysToRemoveByTag as $tag => $keys) {
                    $tag = (string) $tag;
                    $client->hdel($this->context->tagHashKey($tag), ...$keys);
                }

                // 4. Batch update new tag hashes
                foreach ($keysByNewTag as $tag => $keys) {
                    $tag = (string) $tag;
                    $tagHashKey = $this->context->tagHashKey($tag);

                    // Prepare HSET arguments: [key1 => 1, key2 => 1, ...]
                    $hsetArgs = array_fill_keys($keys, StoreContext::TAG_FIELD_VALUE);

                    // Use multi() for tag hash updates (same slot)
                    $multi = $client->multi();
                    $multi->hSet($tagHashKey, $hsetArgs);
                    $multi->hexpire($tagHashKey, $ttl, $keys);
                    $multi->exec();
                }

                // 5. Batch update Registry (Same slot, single command optimization)
                if (! empty($keysByNewTag)) {
                    $zaddArgs = [];

                    foreach ($keysByNewTag as $tag => $keys) {
                        $zaddArgs[] = $expiry;
                        $zaddArgs[] = (string) $tag;
                    }

                    $client->zadd($registryKey, ['GT'], ...$zaddArgs);
                }
            }

            return true;
        });
    }

    /**
     * Execute using Pipeline.
     */
    private function executeUsingPipeline(array $values, int $seconds, array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($values, $seconds, $tags) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $registryKey = $this->context->registryKey();
            $expiry = time() + $seconds;
            $ttl = max(1, $seconds);

            foreach (array_chunk($values, self::CHUNK_SIZE, true) as $chunk) {
                // Step 1: Retrieve old tags for all keys in the chunk
                $pipeline = $client->pipeline();

                foreach ($chunk as $key => $value) {
                    $pipeline->smembers($this->context->reverseIndexKey($key));
                }

                $oldTagsResults = $pipeline->exec();

                // Step 2: Prepare updates
                $keysByNewTag = [];
                $keysToRemoveByTag = [];

                $pipeline = $client->pipeline();
                $i = 0;

                foreach ($chunk as $key => $value) {
                    $oldTags = $oldTagsResults[$i] ?? [];
                    ++$i;

                    // Calculate tags to remove (Old Tags - New Tags)
                    $tagsToRemove = array_diff($oldTags, $tags);

                    foreach ($tagsToRemove as $tag) {
                        $keysToRemoveByTag[$tag][] = $key;
                    }

                    // 1. Store the actual cache value
                    $pipeline->setex(
                        $prefix . $key,
                        $ttl,
                        $this->serialization->serialize($conn, $value)
                    );

                    // 2. Store reverse index of tags for this key
                    $tagsKey = $this->context->reverseIndexKey($key);
                    $pipeline->del($tagsKey); // Clear old tags

                    if (! empty($tags)) {
                        $pipeline->sadd($tagsKey, ...$tags);
                        $pipeline->expire($tagsKey, $ttl);
                    }

                    // Collect keys for batch tag update (New Tags)
                    foreach ($tags as $tag) {
                        $keysByNewTag[$tag][] = $key;
                    }
                }

                // 3. Batch remove from old tags
                foreach ($keysToRemoveByTag as $tag => $keys) {
                    $tag = (string) $tag;
                    $pipeline->hdel($this->context->tagHashKey($tag), ...$keys);
                }

                // 4. Batch update new tag hashes
                foreach ($keysByNewTag as $tag => $keys) {
                    $tag = (string) $tag;
                    $tagHashKey = $this->context->tagHashKey($tag);

                    // Prepare HSET arguments: [key1 => 1, key2 => 1, ...]
                    $hsetArgs = array_fill_keys($keys, StoreContext::TAG_FIELD_VALUE);

                    $pipeline->hSet($tagHashKey, $hsetArgs);
                    $pipeline->hexpire($tagHashKey, $ttl, $keys);
                }

                // Update Registry in batch
                if (! empty($keysByNewTag)) {
                    $zaddArgs = [];

                    foreach ($keysByNewTag as $tag => $keys) {
                        $zaddArgs[] = $expiry;
                        $zaddArgs[] = (string) $tag;
                    }

                    $pipeline->zadd($registryKey, ['GT'], ...$zaddArgs);
                }

                $pipeline->exec();
            }

            return true;
        });
    }
}
