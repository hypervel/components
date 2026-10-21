<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

class Flush
{
    private const int CHUNK_SIZE = 1000;

    /**
     * Create a new flush operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly GetEntries $getEntries,
    ) {
    }

    /**
     * Flush all cache entries for the given tags.
     *
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     */
    public function execute(array $tagIds): void
    {
        $prefix = $this->context->prefix();
        $isCluster = $this->context->isCluster();

        $tagKeys = array_map(fn (string $tagId): string => $prefix . $tagId, $tagIds);
        $entries = $this->getEntries->execute($tagIds);

        foreach ($entries->chunk(self::CHUNK_SIZE) as $chunk) {
            $members = array_values($chunk->all());

            if ($members === []) {
                continue;
            }

            $keys = array_map(fn (string $key): string => $prefix . $key, $members);

            $this->context->withConnection(function (RedisConnection $connection) use ($keys, $members, $tagKeys, $isCluster): void {
                if ($isCluster) {
                    // Remove memberships first so a racing writer can leave only
                    // orphaned metadata for Prune, never an unindexed live value.
                    foreach ($tagKeys as $tagKey) {
                        $connection->zrem($tagKey, ...$members);
                    }

                    $connection->del(...$keys);
                } else {
                    $connection->evalWithShaCache(
                        $this->flushChunkScript(),
                        [...$tagKeys, ...$keys],
                        [count($tagKeys), ...$members],
                    );
                }
            });
        }
    }

    /**
     * Atomically delete scanned values and memberships, preserving concurrent additions.
     */
    protected function flushChunkScript(): string
    {
        return <<<'LUA'
            local tagCount = tonumber(ARGV[1])

            redis.call('DEL', unpack(KEYS, tagCount + 1, #KEYS))

            for i = 1, tagCount do
                redis.call('ZREM', KEYS[i], unpack(ARGV, 2, #ARGV))
            end

            return 1
            LUA;
    }
}
