<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Flush tags using lazy cleanup mode (fast).
 *
 * Skips reading reverse index and cross-tag cleanup. Orphaned hash fields
 * are left for the scheduled cleanup command to remove later.
 *
 * Process:
 * 1. Collect all unique keys from all tags
 * 2. Delete the cache keys and reverse index sets
 * 3. Delete the tag hashes themselves
 *
 * Performance: 5-10x faster than eager mode for large tag sets.
 * Memory impact: Orphaned fields until cleanup runs (~50MB max with hourly cleanup)
 */
class Flush
{
    private const CHUNK_SIZE = 1000;

    /**
     * Create a new flush operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly GetTaggedKeys $getTaggedKeys,
    ) {}

    /**
     * Execute the lazy flush.
     *
     * @param array<int, string|int> $tags Array of tag names to flush
     * @return bool True if successful, false on failure
     */
    public function execute(array $tags): bool
    {
        // 1. Cluster Mode: Must use sequential commands
        if ($this->context->isCluster()) {
            return $this->executeCluster($tags);
        }

        // 2. Standard Mode: Use Pipeline
        return $this->executeUsingPipeline($tags);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($tags) {
            $client = $conn->client();

            // Collect all keys from all tags
            $keyGenerator = function () use ($tags) {
                foreach ($tags as $tag) {
                    $keys = $this->getTaggedKeys->execute((string) $tag);

                    foreach ($keys as $key) {
                        yield $key;
                    }
                }
            };

            $buffer = [];
            $bufferSize = 0;

            foreach ($keyGenerator() as $key) {
                $buffer[$key] = true;
                $bufferSize++;

                if ($bufferSize >= self::CHUNK_SIZE) {
                    $this->processChunkCluster($client, array_keys($buffer));
                    $buffer = [];
                    $bufferSize = 0;
                }
            }

            if ($bufferSize > 0) {
                $this->processChunkCluster($client, array_keys($buffer));
            }

            // Delete the tag hashes themselves and remove from registry
            $registryKey = $this->context->registryKey();

            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $client->del($this->context->tagHashKey($tag));
                $client->zrem($registryKey, $tag);
            }

            return true;
        });
    }

    /**
     * Execute using Pipeline.
     */
    private function executeUsingPipeline(array $tags): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($tags) {
            $client = $conn->client();

            // Collect all keys from all tags
            $keyGenerator = function () use ($tags) {
                foreach ($tags as $tag) {
                    $keys = $this->getTaggedKeys->execute((string) $tag);

                    foreach ($keys as $key) {
                        yield $key;
                    }
                }
            };

            $buffer = [];
            $bufferSize = 0;

            foreach ($keyGenerator() as $key) {
                $buffer[$key] = true;
                $bufferSize++;

                if ($bufferSize >= self::CHUNK_SIZE) {
                    $this->processChunkPipeline($client, array_keys($buffer));
                    $buffer = [];
                    $bufferSize = 0;
                }
            }

            if ($bufferSize > 0) {
                $this->processChunkPipeline($client, array_keys($buffer));
            }

            // Delete the tag hashes themselves and remove from registry
            $registryKey = $this->context->registryKey();
            $pipeline = $client->pipeline();

            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $pipeline->del($this->context->tagHashKey($tag));
                $pipeline->zrem($registryKey, $tag);
            }

            $pipeline->exec();

            return true;
        });
    }

    /**
     * Process a chunk of keys for lazy flush (Cluster Mode).
     *
     * @param \Redis|\RedisCluster $client
     * @param array<int, string> $keys Array of cache keys (without prefix)
     */
    private function processChunkCluster(mixed $client, array $keys): void
    {
        $prefix = $this->context->prefix();

        // Delete reverse indexes for this chunk
        $reverseIndexKeys = array_map(
            fn (string $key): string => $this->context->reverseIndexKey($key),
            $keys
        );

        // Convert to prefixed keys for this chunk
        $prefixedChunk = array_map(
            fn (string $key): string => $prefix . $key,
            $keys
        );

        if (! empty($reverseIndexKeys)) {
            $client->del(...$reverseIndexKeys);
        }

        if (! empty($prefixedChunk)) {
            $client->unlink(...$prefixedChunk);
        }
    }

    /**
     * Process a chunk of keys for lazy flush (Pipeline Mode).
     *
     * @param \Redis|\RedisCluster $client
     * @param array<int, string> $keys Array of cache keys (without prefix)
     */
    private function processChunkPipeline(mixed $client, array $keys): void
    {
        $prefix = $this->context->prefix();

        // Delete reverse indexes for this chunk
        $reverseIndexKeys = array_map(
            fn (string $key): string => $this->context->reverseIndexKey($key),
            $keys
        );

        // Convert to prefixed keys for this chunk
        $prefixedChunk = array_map(
            fn (string $key): string => $prefix . $key,
            $keys
        );

        $pipeline = $client->pipeline();

        if (! empty($reverseIndexKeys)) {
            $pipeline->del(...$reverseIndexKeys);
        }

        if (! empty($prefixedChunk)) {
            $pipeline->unlink(...$prefixedChunk);
        }

        $pipeline->exec();
    }
}
