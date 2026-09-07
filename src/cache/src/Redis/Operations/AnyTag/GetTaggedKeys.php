<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Generator;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\PhpRedis;
use Hypervel\Redis\RedisConnection;

/**
 * Get all keys associated with a tag.
 *
 * Uses adaptive scanning strategy based on hash size:
 * - At or below threshold: Uses HKEYS (faster, loads all into memory)
 * - Above threshold: Uses HSCAN (memory-efficient streaming)
 */
class GetTaggedKeys
{
    /**
     * Default threshold for switching from HKEYS to HSCAN.
     * Above this number of fields, use HSCAN for memory efficiency.
     */
    private const int DEFAULT_SCAN_THRESHOLD = 1000;

    /**
     * Create a new get tagged keys query instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly int $scanThreshold = self::DEFAULT_SCAN_THRESHOLD,
    ) {
    }

    /**
     * Execute the query.
     *
     * @param string $tag The tag name
     * @param int $count HSCAN count parameter (items per iteration)
     * @return Generator<string> Generator yielding cache keys (without prefix)
     */
    public function execute(string $tag, int $count = 1000): Generator
    {
        $tagKey = $this->context->tagHashKey($tag);

        // Check size with a quick connection checkout
        $size = $this->context->withConnection(
            fn (RedisConnection $connection) => $connection->hlen($tagKey)
        );

        if ($size <= $this->scanThreshold) {
            // For small hashes, fetch all at once (safe - data fully fetched before connection release)
            $fields = $this->context->withConnection(
                fn (RedisConnection $connection) => $connection->hkeys($tagKey)
            );

            return $this->arrayToGenerator($fields ?: []);
        }

        // For large hashes, use HSCAN with per-batch connections
        return $this->hscanGenerator($tagKey, $count);
    }

    /**
     * Convert an array to a generator.
     *
     * @param array<string> $items
     * @return Generator<string>
     */
    private function arrayToGenerator(array $items): Generator
    {
        foreach ($items as $item) {
            yield $item;
        }
    }

    /**
     * Create a generator using HSCAN for memory-efficient iteration.
     *
     * Each page owns a short connection checkout so yielding a key never retains a
     * pooled connection across arbitrary caller work.
     *
     * @return Generator<string>
     */
    private function hscanGenerator(string $tagKey, int $count): Generator
    {
        $iterator = PhpRedis::initialScanCursor();

        do {
            // Acquire connection just for this HSCAN batch
            $fields = $this->context->withConnection(
                function (RedisConnection $connection) use ($tagKey, &$iterator, $count): mixed {
                    return $connection->withoutScanPrefix(function () use ($connection, $tagKey, &$iterator, $count): mixed {
                        return $connection->hscan($tagKey, $iterator, null, $count);
                    });
                }
            );

            if ($fields === false || ! is_array($fields)) {
                break;
            }

            // HSCAN returns key-value pairs, we only need keys.
            foreach (array_keys($fields) as $key) {
                yield $key;
            }
        } while ($iterator !== 0);
    }
}
