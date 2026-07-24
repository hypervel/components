<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis;

use Closure;
use DateInterval;
use DateTimeInterface;
use Generator;
use Hypervel\Cache\AnyModeTaggedCache;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\TagSet;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Any-mode tagged cache for Redis 8.0+ enhanced tagging.
 *
 * Uses Redis hashes with field expiration and single-connection operations
 * for tagged writes and remember-style cache misses.
 */
class AnyTaggedCache extends AnyModeTaggedCache
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
    protected TagSet $tags;

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
     * Store an item in the cache.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->store->forget($key);
        }

        $result = $this->store->anyTagOps()->put()->execute($key, $value, $seconds, $this->tags->getNames());

        if ($result) {
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, $key, NullSentinel::unwrap($value), $seconds));
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
            $result = true;

            foreach (array_keys($values) as $key) {
                if (! $this->store->forget((string) $key)) {
                    $result = false;
                }
            }

            return $result;
        }

        $result = $this->store->anyTagOps()->putMany()->execute($values, $seconds, $this->tags->getNames());

        if ($result) {
            foreach ($values as $key => $value) {
                $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, (string) $key, NullSentinel::unwrap($value), $seconds));
            }
        }

        return $result;
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

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
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $result = $this->store->anyTagOps()->forever()->execute($key, $value, $this->tags->getNames());

        if ($result) {
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, $key, NullSentinel::unwrap($value)));
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->store->anyTagOps()->increment()->execute($key, $value, $this->tags->getNames());
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->store->anyTagOps()->decrement()->execute($key, $value, $this->tags->getNames());
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

        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;
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
            $this->event(CacheHit::class, fn (): CacheHit => new CacheHit(null, $key, NullSentinel::unwrap($value)));
        } else {
            $this->event(CacheMissed::class, fn (): CacheMissed => new CacheMissed(null, $key));
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, $key, NullSentinel::unwrap($value), $seconds));
        }

        return NullSentinel::unwrap($value);
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
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        [$value, $wasHit] = $this->store->anyTagOps()->rememberForever()->execute(
            $key,
            $callback,
            $this->tags->getNames()
        );

        if ($wasHit) {
            $this->event(CacheHit::class, fn (): CacheHit => new CacheHit(null, $key, NullSentinel::unwrap($value)));
        } else {
            $this->event(CacheMissed::class, fn (): CacheMissed => new CacheMissed(null, $key));
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, $key, NullSentinel::unwrap($value)));
        }

        return NullSentinel::unwrap($value);
    }

    /**
     * Get the tag set instance (covariant return type).
     */
    public function getTags(): AnyTagSet
    {
        return $this->tags;
    }

    /**
     * Store multiple items in the cache indefinitely.
     */
    protected function putManyForever(array $values): bool
    {
        $result = true;

        foreach ($values as $key => $value) {
            if (! $this->forever((string) $key, $value)) {
                $result = false;
            }
        }

        return $result;
    }
}
