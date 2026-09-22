<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

class RemoveEmptyTags
{
    /**
     * Create a new empty-tag cleanup operation.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Deregister empty hashes while preserving concurrent registrations.
     *
     * @param array<int, string> $tags
     * @return array{empty: int, removed: int}
     */
    public function execute(RedisConnection $connection, array $tags): array
    {
        $tagKeys = array_map($this->context->tagHashKey(...), $tags);
        $registryKey = $this->context->registryKey();

        if (! $connection->isCluster()) {
            /** @var array{int, int} $result */
            $result = $connection->evalWithShaCache($this->removeEmptyTagsScript(), [$registryKey, ...$tagKeys], $tags);

            return ['empty' => $result[0], 'removed' => $result[1]];
        }

        $empty = 0;
        $removed = 0;

        foreach ($tags as $index => $tag) {
            $tagKey = $tagKeys[$index];

            if (! $this->tagHashIsEmpty($connection, $tagKey)) {
                continue;
            }

            $registryRemoved = (int) $connection->zrem($registryKey, $tag);

            // A writer publishes the hash before the registry. If it revived
            // the hash during the cross-slot cleanup, restore only a missing
            // registry member and let the writer's real expiry win.
            if (! $this->tagHashIsEmpty($connection, $tagKey)) {
                $connection->zadd($registryKey, ['NX'], StoreContext::MAX_EXPIRY, $tag);

                continue;
            }

            ++$empty;
            $removed += $registryRemoved;
        }

        return ['empty' => $empty, 'removed' => $removed];
    }

    /**
     * Check the current hash state across concurrent writes.
     *
     * @phpstan-impure Redis may change between calls.
     */
    private function tagHashIsEmpty(RedisConnection $connection, string $tagKey): bool
    {
        return $connection->hlen($tagKey) === 0;
    }

    /**
     * Deregister empty hashes atomically and return the cleanup counts.
     */
    protected function removeEmptyTagsScript(): string
    {
        return <<<'LUA'
            local empty = 0
            local removed = 0

            for i = 2, #KEYS do
                if redis.call('HLEN', KEYS[i]) == 0 then
                    empty = empty + 1
                    removed = removed + redis.call('ZREM', KEYS[1], ARGV[i - 1])
                end
            end

            return {empty, removed}
            LUA;
    }
}
