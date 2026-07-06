<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BadMethodCallException;
use DateInterval;
use DateTimeInterface;
use UnitEnum;

/**
 * Tagged cache for any-mode tag semantics.
 *
 * Tags are invalidation indexes only: items live under their plain cache
 * keys, tags are recorded on writes, and flushing any one tag removes every
 * item written with it. Reads, existence checks, per-key deletes, and TTL
 * adjustments are not tag operations in this mode.
 */
abstract class AnyModeTaggedCache extends TaggedCache
{
    /**
     * Retrieve an item from the cache by key.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function get(array|UnitEnum|string $key, mixed $default = null): mixed
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::get() directly with the full key.'
        );
    }

    /**
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function getRaw(UnitEnum|string $key): mixed
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::get() directly with the full key.'
        );
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function many(array $keys): array
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::many() directly with the full keys.'
        );
    }

    /**
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function manyRaw(array $keys): array
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::many() directly with the full keys.'
        );
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function has(array|UnitEnum|string $key): bool
    {
        throw new BadMethodCallException(
            'Cannot check existence via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::has() directly with the full key.'
        );
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function pull(UnitEnum|string $key, mixed $default = null): mixed
    {
        throw new BadMethodCallException(
            'Cannot pull items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::pull() directly with the full key.'
        );
    }

    /**
     * Remove an item from the cache.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function forget(UnitEnum|string $key): bool
    {
        throw new BadMethodCallException(
            'Cannot forget items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::forget() directly with the full key, or flush() to remove all tagged items.'
        );
    }

    /**
     * Set the expiration of a cached item.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function touch(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        throw new BadMethodCallException(
            'Cannot touch items via tags in any mode. Re-put the item through tags() to change '
            . 'its TTL; a direct Cache::touch() uses the store\'s plain-key semantics.'
        );
    }
}
