<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Flush scanned tag members without deleting concurrently added memberships.
 *
 * Skips reading reverse index and cross-tag cleanup. Orphaned hash fields
 * are left for the scheduled cleanup command to remove later.
 */
class Flush
{
    private const int CHUNK_SIZE = 1000;

    /**
     * Create a new flush operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly GetTaggedKeys $getTaggedKeys,
        private readonly RemoveEmptyTags $removeEmptyTags,
    ) {
    }

    /**
     * Execute the lazy flush.
     *
     * @param array<int, int|string> $tags Array of tag names to flush
     * @return bool True if successful, false on failure
     */
    public function execute(array $tags): bool
    {
        if ($tags === []) {
            return true;
        }

        $tags = array_map(strval(...), $tags);
        $isCluster = $this->context->isCluster();

        // Flush one tag at a time so each chunk is only removed from the tag it was scanned
        // from. Removing every chunk from every requested tag multiplies the work by the tag
        // count and blocks Redis when many tags are flushed together.
        foreach ($tags as $tag) {
            $tagKey = $this->context->tagHashKey($tag);
            $buffer = [];

            foreach ($this->getTaggedKeys->execute($tag) as $key) {
                $buffer[] = $key;

                if (count($buffer) === self::CHUNK_SIZE) {
                    $this->flushChunk($buffer, $tagKey, $isCluster);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->flushChunk($buffer, $tagKey, $isCluster);
            }
        }

        $this->context->withConnection(
            fn (RedisConnection $connection): array => $this->removeEmptyTags->execute($connection, $tags),
        );

        return true;
    }

    /**
     * Delete a bounded chunk of values and their scanned memberships.
     *
     * @param array<int, int|string> $keys Cache keys without the store prefix
     */
    private function flushChunk(array $keys, string $tagKey, bool $isCluster): void
    {
        $keys = array_map(strval(...), $keys);
        $prefix = $this->context->prefix();
        $reverseIndexKeys = array_map($this->context->reverseIndexKey(...), $keys);
        $valueKeys = array_map(fn (string $key): string => $prefix . $key, $keys);

        $this->context->withConnection(function (RedisConnection $connection) use ($keys, $tagKey, $reverseIndexKeys, $valueKeys, $isCluster): void {
            if ($isCluster) {
                // Remove memberships before values so racing writers leave only
                // orphaned metadata for Prune, never unindexed live values.
                $connection->hdel($tagKey, ...$keys);
                $connection->del(...$reverseIndexKeys);
                $connection->unlink(...$valueKeys);

                return;
            }

            $connection->evalWithShaCache(
                $this->flushChunkScript(),
                [$tagKey, ...$reverseIndexKeys, ...$valueKeys],
                [count($keys), ...$keys],
            );
        });
    }

    /**
     * Remove only scanned fields, their reverse indexes and values atomically.
     */
    protected function flushChunkScript(): string
    {
        return <<<'LUA'
            local keyCount = tonumber(ARGV[1])

            redis.call('HDEL', KEYS[1], unpack(ARGV, 2, #ARGV))
            redis.call('DEL', unpack(KEYS, 2, keyCount + 1))
            redis.call('UNLINK', unpack(KEYS, keyCount + 2, #KEYS))

            return 1
            LUA;
    }
}
