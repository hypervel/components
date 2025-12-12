<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;
use RedisCluster;

/**
 * Flushes all cache entries associated with all tags.
 *
 * This operation:
 * 1. Gets all cache keys from the tag sorted sets
 * 2. Deletes the cache keys in chunks (1000 at a time) using a single connection
 * 3. Deletes the tag sorted sets themselves
 *
 * Optimized to use a single connection checkout for all chunk deletions,
 * with pipeline batching in standard mode for maximum efficiency.
 */
class Flush
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly StoreContext $context,
        private readonly GetEntries $getEntries,
    ) {}

    /**
     * Flush all cache entries for the given tags.
     *
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @param array<string> $tagNames Array of tag names (e.g., ["users", "posts"])
     */
    public function execute(array $tagIds, array $tagNames): void
    {
        $this->flushValues($tagIds);
        $this->flushTags($tagNames);
    }

    /**
     * Flush the individual cache entries for the tags.
     *
     * Uses a single connection for all chunk deletions to avoid pool
     * checkout/release overhead per chunk. In standard mode, uses pipeline
     * for batching. In cluster mode, uses sequential commands.
     *
     * @param array<string> $tagIds Array of tag identifiers
     */
    private function flushValues(array $tagIds): void
    {
        $prefix = $this->context->prefix();

        // Collect all entries and prepare chunks
        // (materialize the LazyCollection to get prefixed keys)
        $entries = $this->getEntries->execute($tagIds)
            ->map(fn (string $key) => $prefix . $key);

        // Use a single connection for all chunk deletions
        $this->context->withConnection(function (RedisConnection $conn) use ($entries) {
            $client = $conn->client();
            $isCluster = $client instanceof RedisCluster;

            foreach ($entries->chunk(self::CHUNK_SIZE) as $chunk) {
                $keys = $chunk->all();

                if (empty($keys)) {
                    continue;
                }

                if ($isCluster) {
                    // Cluster mode: sequential DEL (keys may be in different slots)
                    $client->del(...$keys);
                } else {
                    // Standard mode: pipeline for batching
                    $this->deleteChunkPipelined($client, $keys);
                }
            }
        });
    }

    /**
     * Delete a chunk of keys using pipeline.
     *
     * @param \Redis|object $client The Redis client (or mock in tests)
     * @param array<string> $keys Keys to delete
     */
    private function deleteChunkPipelined(mixed $client, array $keys): void
    {
        $pipeline = $client->pipeline();
        $pipeline->del(...$keys);
        $pipeline->exec();
    }

    /**
     * Delete the tag sorted sets.
     *
     * Uses variadic del() to delete all tag keys in a single Redis call.
     *
     * @param array<string> $tagNames Array of tag names
     */
    private function flushTags(array $tagNames): void
    {
        if (empty($tagNames)) {
            return;
        }

        $this->context->withConnection(function (RedisConnection $conn) use ($tagNames) {
            $tagKeys = array_map(
                fn (string $name) => $this->context->tagHashKey($name),
                $tagNames
            );

            $conn->del(...$tagKeys);
        });
    }
}
