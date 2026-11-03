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

        // Flush one tag at a time so each chunk is only removed from the tag it was scanned
        // from. Removing every chunk from every requested tag multiplies the work by the tag
        // count and blocks Redis when many tags are flushed together.
        foreach ($tagIds as $tagId) {
            $tagKey = $prefix . $tagId;

            foreach ($this->getEntries->execute([$tagId])->chunk(self::CHUNK_SIZE) as $chunk) {
                $members = array_values($chunk->all());
                $keys = array_map(fn (string $key): string => $prefix . $key, $members);

                $this->context->withConnection(function (RedisConnection $connection) use ($keys, $members, $tagKey, $isCluster): void {
                    if ($isCluster) {
                        // Remove memberships first so a racing writer can leave only
                        // orphaned metadata for Prune, never an unindexed live value.
                        $connection->zrem($tagKey, ...$members);
                        $connection->del(...$keys);
                    } else {
                        $connection->evalWithShaCache($this->flushChunkScript(), [$tagKey, ...$keys], $members);
                    }
                });
            }
        }
    }

    /**
     * Atomically delete scanned values and their memberships, preserving concurrent additions.
     */
    protected function flushChunkScript(): string
    {
        return <<<'LUA'
            redis.call('DEL', unpack(KEYS, 2, #KEYS))
            redis.call('ZREM', KEYS[1], unpack(ARGV))

            return 1
            LUA;
    }
}
