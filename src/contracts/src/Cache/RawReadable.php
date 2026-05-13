<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

use UnitEnum;

/**
 * Capability interface for cache layers that expose raw reads (returns the
 * value as it was stored, including the internal NullSentinel::VALUE marker
 * used by the nullable cache methods).
 *
 * @internal This capability exists for the cache layer's own sentinel-awareness.
 *   App code should use Cache::get() / Cache::many() / Cache::pull() etc., which
 *   unwrap sentinels to null. Only implement this on custom stores that wrap
 *   other stores by bouncing reads through a Repository — in that case,
 *   implementing RawReadable (and routing your internal memoization / failover
 *   logic through the inner repository's getRaw() / manyRaw()) is what keeps
 *   rememberNullable() performant across your wrapper.
 *
 * Plain stores that store and retrieve values directly (RedisStore, FileStore,
 * DatabaseStore, ArrayStore, SwooleStore, etc.) already return raw stored
 * values from get() / many() and do NOT need to implement this interface —
 * Repository::getRaw() / manyRaw() will fall back to get() / many() on stores
 * that don't implement RawReadable.
 */
interface RawReadable
{
    /**
     * Retrieve an item from the cache by key without unwrapping sentinels.
     *
     * @return mixed the raw stored value — may be null (genuine miss),
     *               Hypervel\Cache\NullSentinel::VALUE (cached-null via the nullable
     *               cache methods), or a real cached value
     */
    public function getRaw(UnitEnum|string $key): mixed;

    /**
     * Retrieve multiple items by key without unwrapping sentinels.
     *
     * @param list<string> $keys
     * @return array<string, mixed> keyed by the input keys; values are raw
     *                              (may include null, NullSentinel::VALUE, or real values)
     */
    public function manyRaw(array $keys): array;
}
