<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Generator;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Get all items (keys and values) for the given tags.
 *
 * Iterates through all keys associated with the given tags,
 * fetching their values in batches for efficiency.
 */
class GetTagItems
{
    private const CHUNK_SIZE = 1000;

    /**
     * Create a new tag items query instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
        private readonly GetTaggedKeys $getTaggedKeys,
    ) {
    }

    /**
     * Execute the query.
     *
     * @param array<int, int|string> $tags Array of tag names
     * @return Generator Yields key => value pairs
     */
    public function execute(array $tags): Generator
    {
        $seenKeys = [];

        foreach ($tags as $tag) {
            $keys = $this->getTaggedKeys->execute((string) $tag);
            $keyBuffer = [];

            foreach ($keys as $key) {
                if (isset($seenKeys[$key])) {
                    continue;
                }

                $seenKeys[$key] = true;
                $keyBuffer[] = $key;

                if (count($keyBuffer) >= self::CHUNK_SIZE) {
                    yield from $this->fetchValues($keyBuffer);
                    $keyBuffer = [];
                }
            }

            if (! empty($keyBuffer)) {
                yield from $this->fetchValues($keyBuffer);
            }
        }
    }

    /**
     * Fetch values for a list of keys.
     *
     * @param array<int, string> $keys Array of cache keys (without prefix)
     * @return Generator Yields key => value pairs
     */
    private function fetchValues(array $keys): Generator
    {
        if (empty($keys)) {
            return;
        }

        $prefix = $this->context->prefix();
        $prefixedKeys = array_map(fn ($key): string => $prefix . $key, $keys);

        $results = $this->context->withConnection(
            function (RedisConnection $conn) use ($prefixedKeys, $keys) {
                $values = $conn->mget($prefixedKeys);
                $items = [];

                foreach ($values as $index => $value) {
                    if ($value !== false && $value !== null) {
                        $items[$keys[$index]] = $this->serialization->unserialize($conn, $value);
                    }
                }

                return $items;
            }
        );

        yield from $results;
    }
}
