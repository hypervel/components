<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Support\LazyCollection;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Retrieves all cache key entries from all tag sorted sets.
 *
 * Uses ZSCAN for efficient cursor-based iteration over potentially large
 * sorted sets. Each tag's entries are collected within a single connection
 * hold for efficiency.
 */
class GetEntries
{
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Get all cache key entries across the given tag sorted sets.
     *
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     * @return LazyCollection<int, string> Lazy collection yielding cache keys (without prefix)
     */
    public function execute(array $tagIds): LazyCollection
    {
        $context = $this->context;
        $prefix = $this->context->prefix();

        // phpredis 6.1.0+ uses null as initial cursor value, older versions use '0'
        $defaultCursorValue = match (true) {
            version_compare(phpversion('redis'), '6.1.0', '>=') => null,
            default => '0',
        };

        return new LazyCollection(function () use ($context, $prefix, $tagIds, $defaultCursorValue) {
            foreach ($tagIds as $tagId) {
                // Collect all entries for this tag within one connection hold
                $tagEntries = $context->withConnection(function (RedisConnection $connection) use ($prefix, $tagId, $defaultCursorValue) {
                    $cursor = $defaultCursorValue;
                    $allEntries = [];

                    do {
                        $entries = $connection->zscan(
                            $prefix . $tagId,
                            $cursor,
                            '*',
                            1000
                        );

                        if (! is_array($entries)) {
                            break;
                        }

                        $allEntries = array_merge($allEntries, array_keys($entries));
                    } while (((string) $cursor) !== $defaultCursorValue);

                    return array_unique($allEntries);
                });

                foreach ($tagEntries as $entry) {
                    yield $entry;
                }
            }
        });
    }
}
