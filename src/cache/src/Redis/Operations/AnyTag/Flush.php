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
        $tagKeys = array_map($this->context->tagHashKey(...), $tags);
        $isCluster = $this->context->isCluster();

        $buffer = [];

        foreach ($tags as $tag) {
            foreach ($this->getTaggedKeys->execute($tag) as $key) {
                $buffer[$key] = true;

                if (count($buffer) === self::CHUNK_SIZE) {
                    $this->flushChunk(array_keys($buffer), $tagKeys, $isCluster);
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            $this->flushChunk(array_keys($buffer), $tagKeys, $isCluster);
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
     * @param array<int, string> $tagKeys
     */
    private function flushChunk(array $keys, array $tagKeys, bool $isCluster): void
    {
        $keys = array_map(strval(...), $keys);
        $prefix = $this->context->prefix();
        $reverseIndexKeys = array_map($this->context->reverseIndexKey(...), $keys);
        $valueKeys = array_map(fn (string $key): string => $prefix . $key, $keys);

        $this->context->withConnection(function (RedisConnection $connection) use ($keys, $tagKeys, $reverseIndexKeys, $valueKeys, $isCluster): void {
            if ($isCluster) {
                // Remove memberships before values so racing writers leave only
                // orphaned metadata for Prune, never unindexed live values.
                foreach ($tagKeys as $tagKey) {
                    $connection->hdel($tagKey, ...$keys);
                }

                $connection->del(...$reverseIndexKeys);
                $connection->unlink(...$valueKeys);

                return;
            }

            $connection->evalWithShaCache(
                $this->flushChunkScript(),
                [...$tagKeys, ...$reverseIndexKeys, ...$valueKeys],
                [count($tagKeys), count($keys), ...$keys],
            );
        });
    }

    /**
     * Remove only scanned fields, their reverse indexes and values atomically.
     */
    protected function flushChunkScript(): string
    {
        return <<<'LUA'
            local tagCount = tonumber(ARGV[1])
            local keyCount = tonumber(ARGV[2])

            for i = 1, tagCount do
                redis.call('HDEL', KEYS[i], unpack(ARGV, 3, #ARGV))
            end

            redis.call('DEL', unpack(KEYS, tagCount + 1, tagCount + keyCount))
            redis.call('UNLINK', unpack(KEYS, tagCount + keyCount + 1, #KEYS))

            return 1
            LUA;
    }
}
