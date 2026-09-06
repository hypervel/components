<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Generator;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\PhpRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\LazyCollection;

class GetEntries
{
    /**
     * Create a new get-entries operation instance.
     */
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

        return new LazyCollection(function () use ($context, $prefix, $tagIds): Generator {
            foreach ($tagIds as $tagId) {
                $cursor = PhpRedis::initialScanCursor();
                $seen = [];

                do {
                    $entries = $context->withConnection(
                        function (RedisConnection $connection) use ($prefix, $tagId, &$cursor): mixed {
                            return $connection->withoutScanPrefix(function () use ($connection, $prefix, $tagId, &$cursor): mixed {
                                return $connection->zscan($prefix . $tagId, $cursor, '*', 1000);
                            });
                        }
                    );

                    if (! is_array($entries)) {
                        break;
                    }

                    foreach (array_keys($entries) as $entry) {
                        $entry = (string) $entry;

                        if (isset($seen[$entry])) {
                            continue;
                        }

                        $seen[$entry] = true;

                        yield $entry;
                    }
                } while ($cursor !== 0);
            }
        });
    }
}
