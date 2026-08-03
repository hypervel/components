<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

class Flush
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly StoreContext $context,
        private readonly GetEntries $getEntries,
    ) {
    }

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
     * @param array<string> $tagIds Array of tag identifiers
     */
    private function flushValues(array $tagIds): void
    {
        $prefix = $this->context->prefix();
        $isCluster = $this->context->isCluster();

        $entries = $this->getEntries->execute($tagIds)
            ->map(fn (string $key) => $prefix . $key);

        foreach ($entries->chunk(self::CHUNK_SIZE) as $chunk) {
            $keys = $chunk->all();

            if (empty($keys)) {
                continue;
            }

            $this->context->withConnection(function (RedisConnection $connection) use ($keys, $isCluster) {
                // Cluster keys may occupy different slots and cannot share a pipeline.
                if ($isCluster) {
                    $connection->del(...$keys);
                } else {
                    $this->deleteChunkPipelined($connection, $keys);
                }
            });
        }
    }

    /**
     * Delete a chunk of keys using pipeline.
     *
     * @param RedisConnection $connection The Redis connection
     * @param array<string> $keys Keys to delete
     */
    private function deleteChunkPipelined(RedisConnection $connection, array $keys): void
    {
        $pipeline = $connection->pipeline();
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

        $this->context->withConnection(function (RedisConnection $connection) use ($tagNames) {
            $tagKeys = array_map(
                fn (string $name) => $this->context->tagHashKey($name),
                $tagNames
            );

            $connection->del(...$tagKeys);
        });
    }
}
