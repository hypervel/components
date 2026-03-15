<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis;

use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Generator;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Any-mode tagged cache for Redis 8.0+ enhanced tagging.
 *
 * Key differences from AllTaggedCache:
 * - Tags are for WRITING and FLUSHING only, not for scoped reads
 * - get() throws exception - use Cache::get() directly
 * - flush() deletes items with ANY of the specified tags (any semantics)
 * - Uses HSETEX for automatic hash field expiration
 */
class AnyTaggedCache extends TaggedCache
{
    /**
     * The cache store implementation.
     *
     * @var RedisStore
     */
    protected Store $store;

    /**
     * The tag set instance.
     *
     * @var AnyTagSet
     */
    protected \Hypervel\Cache\TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(
        RedisStore $store,
        AnyTagSet $tags,
    ) {
        parent::__construct($store, $tags);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @throws BadMethodCallException Always - tags are for writing/flushing only
     */
    public function get(array|UnitEnum|string $key, mixed $default = null): mixed
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::get() directly with the full key.'
        );
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @throws BadMethodCallException Always - tags are for writing/flushing only
     */
    public function many(array $keys): array
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::many() directly with the full keys.'
        );
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @throws BadMethodCallException Always - tags are for writing/flushing only
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
     * @throws BadMethodCallException Always - tags are for writing/flushing only
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
     * @throws BadMethodCallException Always - tags are for writing/flushing only
     */
    public function forget(UnitEnum|string $key): bool
    {
        throw new BadMethodCallException(
            'Cannot forget items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::forget() directly with the full key, or flush() to remove all tagged items.'
        );
    }

    /**
     * Store an item in the cache.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = enum_value($key);

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            // Can't forget via tags, just return false
            return false;
        }

        $result = $this->store->anyTagOps()->put()->execute($key, $value, $seconds, $this->tags->getNames());

        if ($result) {
            $this->event(new KeyWritten(null, $key, $value, $seconds));
        }

        return $result;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->putManyForever($values);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return false;
        }

        $result = $this->store->anyTagOps()->putMany()->execute($values, $seconds, $this->tags->getNames());

        if ($result) {
            foreach ($values as $key => $value) {
                $this->event(new KeyWritten(null, $key, $value, $seconds));
            }
        }

        return $result;
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = enum_value($key);

        if ($ttl === null) {
            // Default to 1 year for "null" TTL on add
            $seconds = 31536000;
        } else {
            $seconds = $this->getSeconds($ttl);

            if ($seconds <= 0) {
                return false;
            }
        }

        return $this->store->anyTagOps()->add()->execute($key, $value, $seconds, $this->tags->getNames());
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = enum_value($key);

        $result = $this->store->anyTagOps()->forever()->execute($key, $value, $this->tags->getNames());

        if ($result) {
            $this->event(new KeyWritten(null, $key, $value));
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->store->anyTagOps()->increment()->execute(enum_value($key), $value, $this->tags->getNames());
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->store->anyTagOps()->decrement()->execute(enum_value($key), $value, $this->tags->getNames());
    }

    /**
     * Remove all items from the cache that have any of the specified tags.
     */
    public function flush(): bool
    {
        $this->event(new CacheFlushing(null));

        $this->tags->flush();

        $this->event(new CacheFlushed(null));

        return true;
    }

    /**
     * Get all items (keys and values) tagged with the current tags.
     *
     * This is useful for debugging or bulk operations on tagged items.
     *
     * @return Generator<string, mixed>
     */
    public function items(): Generator
    {
        return $this->store->anyTagOps()->getTagItems()->execute($this->tags->getNames());
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * Optimized to use a single connection for both GET and PUT operations,
     * avoiding double pool overhead for cache misses.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function remember(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl, Closure $callback): mixed
    {
        if ($ttl === null) {
            return $this->rememberForever($key, $callback);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            // Invalid TTL, just execute callback without caching
            return $callback();
        }

        [$value, $wasHit] = $this->store->anyTagOps()->remember()->execute(
            $key,
            $seconds,
            $callback,
            $this->tags->getNames()
        );

        if ($wasHit) {
            $this->event(new CacheHit(null, $key, $value));
        } else {
            $this->event(new CacheMissed(null, $key));
            $this->event(new KeyWritten(null, $key, $value, $seconds));
        }

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * Optimized to use a single connection for both GET and SET operations,
     * avoiding double pool overhead for cache misses.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function rememberForever(UnitEnum|string $key, Closure $callback): mixed
    {
        [$value, $wasHit] = $this->store->anyTagOps()->rememberForever()->execute(
            $key,
            $callback,
            $this->tags->getNames()
        );

        if ($wasHit) {
            $this->event(new CacheHit(null, $key, $value));
        } else {
            $this->event(new CacheMissed(null, $key));
            $this->event(new KeyWritten(null, $key, $value));
        }

        return $value;
    }

    /**
     * Get the tag set instance (covariant return type).
     */
    public function getTags(): AnyTagSet
    {
        return $this->tags;
    }

    /**
     * Format the key for a cache item.
     *
     * In any mode, keys are NOT namespaced by tags.
     * Tags are only for invalidation, not for scoping reads.
     */
    protected function itemKey(string $key): string
    {
        return $key;
    }

    /**
     * Store multiple items in the cache indefinitely.
     */
    protected function putManyForever(array $values): bool
    {
        $result = true;

        foreach ($values as $key => $value) {
            if (! $this->forever($key, $value)) {
                $result = false;
            }
        }

        return $result;
    }
}
