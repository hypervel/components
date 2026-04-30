<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use ArrayAccess;
use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheLocksFlushed;
use Hypervel\Cache\Events\CacheLocksFlushFailed;
use Hypervel\Cache\Events\CacheLocksFlushing;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Cache\RawReadable;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Carbon;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\defer;
use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Contracts\Cache\Store
 */
class Repository implements ArrayAccess, CacheContract, RawReadable
{
    use InteractsWithTime;
    use Macroable {
        __call as macroCall;
    }

    /**
     * The cache store implementation.
     */
    protected Store $store;

    /**
     * The event dispatcher implementation.
     */
    protected ?Dispatcher $events = null;

    /**
     * The default number of seconds to store items.
     */
    protected ?int $default = 3600;

    /**
     * The cache store configuration.
     */
    protected array $config = [];

    /**
     * Create a new cache repository instance.
     */
    public function __construct(Store $store, array $config = [])
    {
        $this->store = $store;
        $this->config = $config;
    }

    /**
     * Determine if an item exists in the cache.
     */
    public function has(array|UnitEnum|string $key): bool
    {
        return ! is_null($this->get($key));
    }

    /**
     * Determine if an item doesn't exist in the cache.
     */
    public function missing(UnitEnum|string $key): bool
    {
        return ! $this->has($key);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @template TCacheValue
     *
     * @param (Closure(): TCacheValue)|TCacheValue $default
     *
     * @return (TCacheValue is null ? mixed : TCacheValue)
     */
    public function get(array|UnitEnum|string $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

        // NullSentinel::unwrap collapses a cached sentinel to null, so a sentinel
        // and a genuine miss both resolve to the default — matching Laravel's
        // convention that `put('k', null)` + `get('k', 'default')` returns 'default'.
        return NullSentinel::unwrap($this->getRaw($key)) ?? value($default);
    }

    /**
     * Retrieve multiple items from the cache by key.
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        $resolvedKeys = collect($keys)->map(function ($value, $key) {
            return is_string($key) ? $key : (string) enum_value($value);
        })->values()->all();

        // manyRaw() fires RetrievingManyKeys + per-key CacheHit/CacheMissed events and
        // routes through the RawReadable raw-read path for wrapper stores — so a cached
        // sentinel is correctly classified as CacheHit rather than CacheMissed.
        $values = $this->manyRaw($resolvedKeys);

        return collect($values)->map(function ($value, $key) use ($keys) {
            return $this->handleManyResult($keys, (string) $key, $value);
        })->all();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $defaults = [];

        foreach ($keys as $key) {
            $defaults[enum_value($key)] = $default;
        }

        return $this->many($defaults);
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @template TCacheValue
     *
     * @param (Closure(): TCacheValue)|TCacheValue $default
     *
     * @return (TCacheValue is null ? mixed : TCacheValue)
     */
    public function pull(UnitEnum|string $key, mixed $default = null): mixed
    {
        return tap($this->get($key, $default), function () use ($key) {
            $this->forget($key);
        });
    }

    /**
     * Retrieve a string item from the cache.
     *
     * @param null|(Closure(): (null|string))|string $default
     *
     * @throws InvalidArgumentException
     */
    public function string(UnitEnum|string $key, callable|string|null $default = null): string
    {
        $value = $this->get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be a string, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Retrieve an integer item from the cache.
     *
     * @param null|(Closure(): (null|int))|int $default
     *
     * @throws InvalidArgumentException
     */
    public function integer(UnitEnum|string $key, callable|int|null $default = null): int
    {
        $value = $this->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            sprintf('Cache value for key [%s] must be an integer, %s given.', $key, gettype($value))
        );
    }

    /**
     * Retrieve a float item from the cache.
     *
     * @param null|(Closure(): (null|float))|float $default
     *
     * @throws InvalidArgumentException
     */
    public function float(UnitEnum|string $key, callable|float|null $default = null): float
    {
        $value = $this->get($key, $default);

        if (is_float($value)) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
            return (float) $value;
        }

        throw new InvalidArgumentException(
            sprintf('Cache value for key [%s] must be a float, %s given.', $key, gettype($value))
        );
    }

    /**
     * Retrieve a boolean item from the cache.
     *
     * @param null|bool|(Closure(): (null|bool)) $default
     *
     * @throws InvalidArgumentException
     */
    public function boolean(UnitEnum|string $key, callable|bool|null $default = null): bool
    {
        $value = $this->get($key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be a boolean, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Retrieve an array item from the cache.
     *
     * @param null|array<array-key, mixed>|(Closure(): (null|array<array-key, mixed>)) $default
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function array(UnitEnum|string $key, callable|array|null $default = null): array
    {
        $value = $this->get($key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be an array, %s given.', $key, gettype($value))
            );
        }

        return $value;
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
            return $this->forget($key);
        }

        $this->event(
            WritingKey::class,
            fn (): WritingKey => new WritingKey($this->getName(), $key, NullSentinel::unwrap($value), $seconds)
        );

        $result = $this->store->put($this->itemKey($key), $value, $seconds);
        if ($result) {
            $this->event(
                KeyWritten::class,
                fn (): KeyWritten => new KeyWritten($this->getName(), $key, NullSentinel::unwrap($value), $seconds)
            );
        } else {
            $this->event(
                KeyWriteFailed::class,
                fn (): KeyWriteFailed => new KeyWriteFailed($this->getName(), $key, NullSentinel::unwrap($value), $seconds)
            );
        }

        return $result;
    }

    /**
     * Store an item in the cache.
     */
    public function set(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
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
            return $this->deleteMultiple(array_map(static fn ($key) => (string) $key, array_keys($values)));
        }

        $this->event(
            WritingManyKeys::class,
            fn (): WritingManyKeys => new WritingManyKeys(
                $this->getName(),
                array_map(static fn ($key) => (string) $key, array_keys($values)),
                array_map(NullSentinel::unwrap(...), array_values($values)),
                $seconds
            )
        );

        $result = $this->store->putMany($values, $seconds);

        foreach ($values as $key => $value) {
            if ($result) {
                $this->event(
                    KeyWritten::class,
                    fn (): KeyWritten => new KeyWritten($this->getName(), (string) $key, NullSentinel::unwrap($value), $seconds)
                );
            } else {
                $this->event(
                    KeyWriteFailed::class,
                    fn (): KeyWriteFailed => new KeyWriteFailed($this->getName(), (string) $key, NullSentinel::unwrap($value), $seconds)
                );
            }
        }

        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        return $this->putMany(is_array($values) ? $values : iterator_to_array($values), $ttl);
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = enum_value($key);

        $seconds = null;

        if ($ttl !== null) {
            $seconds = $this->getSeconds($ttl);

            if ($seconds <= 0) {
                return false;
            }

            // If the store has an "add" method we will call the method on the store so it
            // has a chance to override this logic. Some drivers better support the way
            // this operation should work with a total "atomic" implementation of it.
            if (method_exists($this->store, 'add')) {
                return $this->store->add(
                    $this->itemKey($key),
                    $value,
                    $seconds
                );
            }
        }

        // If the value did not exist in the cache, we will put the value in the cache
        // so it exists for subsequent requests. Then, we will return true so it is
        // easy to know if the value gets added. Otherwise, we will return false.
        if (is_null($this->get($key))) {
            return $this->put($key, $value, $seconds);
        }

        return false;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->store->increment(enum_value($key), $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->store->decrement(enum_value($key), $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = enum_value($key);

        $this->event(WritingKey::class, fn (): WritingKey => new WritingKey($this->getName(), $key, NullSentinel::unwrap($value)));

        $result = $this->store->forever($this->itemKey($key), $value);

        if ($result) {
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten($this->getName(), $key, NullSentinel::unwrap($value)));
        } else {
            $this->event(
                KeyWriteFailed::class,
                fn (): KeyWriteFailed => new KeyWriteFailed($this->getName(), $key, NullSentinel::unwrap($value))
            );
        }

        return $result;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function remember(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl, Closure $callback): mixed
    {
        $remember = function () use ($key, $ttl, $callback) {
            $value = $this->getRaw($key);

            // Hit — including cached sentinels. Unwrap before returning.
            if (! is_null($value)) {
                return NullSentinel::unwrap($value);
            }

            // Miss — run callback and store the raw result (may be a sentinel if
            // the caller is rememberNullable(), which wraps the callback).
            $value = $callback();

            $this->put($key, $value, value($ttl, $value));

            return NullSentinel::unwrap($value);
        };

        return method_exists($this->store, 'withPinnedConnection')
            ? $this->store->withPinnedConnection($remember)
            : $remember();
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * Unlike remember(), a null return from $callback is stored (as the internal
     * NullSentinel::VALUE marker) and returned as null on subsequent calls rather
     * than triggering re-execution. Public accessors (get, many, pull, has, etc.)
     * unwrap the sentinel automatically — callers never see it.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function rememberNullable(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl, Closure $callback): mixed
    {
        return $this->remember($key, $ttl, fn () => $callback() ?? NullSentinel::VALUE);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function sear(UnitEnum|string $key, Closure $callback): mixed
    {
        return $this->rememberForever($key, $callback);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * Alias for rememberForeverNullable().
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function searNullable(UnitEnum|string $key, Closure $callback): mixed
    {
        return $this->rememberForeverNullable($key, $callback);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function rememberForever(UnitEnum|string $key, Closure $callback): mixed
    {
        $remember = function () use ($key, $callback) {
            $value = $this->getRaw($key);

            if (! is_null($value)) {
                return NullSentinel::unwrap($value);
            }

            $this->forever($key, $value = $callback());

            return NullSentinel::unwrap($value);
        };

        return method_exists($this->store, 'withPinnedConnection')
            ? $this->store->withPinnedConnection($remember)
            : $remember();
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * Unlike rememberForever(), a null return from $callback is stored (as the
     * internal NullSentinel::VALUE marker) and returned as null on subsequent
     * calls rather than triggering re-execution. Public accessors unwrap the
     * sentinel automatically.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function rememberForeverNullable(UnitEnum|string $key, Closure $callback): mixed
    {
        return $this->rememberForever($key, fn () => $callback() ?? NullSentinel::VALUE);
    }

    /**
     * Retrieve an item from the cache by key, refreshing it in the background if it is stale.
     *
     * @template TCacheValue
     *
     * @param array{ 0: DateInterval|DateTimeInterface|int, 1: DateInterval|DateTimeInterface|int } $ttl
     * @param callable(): TCacheValue $callback
     * @param null|array{ seconds?: int, owner?: string } $lock
     * @return TCacheValue
     */
    public function flexible(UnitEnum|string $key, array $ttl, mixed $callback, ?array $lock = null, bool $alwaysDefer = false): mixed
    {
        $key = enum_value($key);
        $markerKey = "hypervel:cache:flexible:created:{$key}";

        [$key => $value, $markerKey => $created] = $this->manyRaw([$key, $markerKey]);

        if (in_array(null, [$value, $created], true)) {
            $stored = value($callback);

            $this->putMany([
                $key => $stored,
                $markerKey => Carbon::now()->getTimestamp(),
            ], $ttl[1]);

            return NullSentinel::unwrap($stored);
        }

        if (($created + $this->getSeconds($ttl[0])) > Carbon::now()->getTimestamp()) {
            return NullSentinel::unwrap($value);
        }

        $refresh = function () use ($key, $markerKey, $ttl, $callback, $lock, $created) {
            $this->store->lock( // @phpstan-ignore method.notFound (lock() is on LockProvider, not Store contract)
                "hypervel:cache:flexible:lock:{$key}",
                $lock['seconds'] ?? 0,
                $lock['owner'] ?? null,
            )->get(function () use ($key, $markerKey, $callback, $created, $ttl) {
                // Re-check the marker inside the lock. Single key, so getRaw is the
                // right tool here — no need to batch.
                if ($created !== $this->getRaw($markerKey)) {
                    return;
                }

                $this->putMany([
                    $key => value($callback),
                    $markerKey => Carbon::now()->getTimestamp(),
                ], $ttl[1]);
            });
        };

        defer($refresh, "hypervel:cache:flexible:{$key}", $alwaysDefer);

        return NullSentinel::unwrap($value);
    }

    /**
     * Retrieve an item from the cache by key, refreshing it in the background if it is stale.
     *
     * Unlike flexible(), a null return from $callback is stored (as the internal
     * NullSentinel::VALUE marker) and returned as null on subsequent calls rather
     * than triggering re-execution. Public accessors unwrap the sentinel
     * automatically.
     *
     * Inherits flexible()'s support matrix: unsupported on any-mode tagged caches
     * (tags()->flexibleNullable() on a TagMode::Any store throws the same
     * BadMethodCallException that tags()->flexible() does, because flexible()
     * internally reads via manyRaw() (initial batched read) and getRaw() (refresh
     * closure), both of which AnyTaggedCache overrides to throw in any-mode).
     *
     * @template TCacheValue
     *
     * @param array{ 0: DateInterval|DateTimeInterface|int, 1: DateInterval|DateTimeInterface|int } $ttl
     * @param callable(): TCacheValue $callback
     * @param null|array{ seconds?: int, owner?: string } $lock
     * @return TCacheValue
     */
    public function flexibleNullable(UnitEnum|string $key, array $ttl, mixed $callback, ?array $lock = null, bool $alwaysDefer = false): mixed
    {
        return $this->flexible($key, $ttl, fn () => value($callback) ?? NullSentinel::VALUE, $lock, $alwaysDefer);
    }

    /**
     * Set the expiration of a cached item; null TTL will retain the item forever.
     */
    public function touch(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $value = $this->get($key);

        if (is_null($value)) {
            return false;
        }

        return is_null($ttl)
            ? $this->forever($key, $value)
            : $this->store->touch($this->itemKey($key), $this->getSeconds($ttl));
    }

    /**
     * Execute a callback while holding an atomic lock on a cache mutex to prevent overlapping calls.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     * @return TReturn
     *
     * @throws LockTimeoutException
     */
    public function withoutOverlapping(UnitEnum|string $key, callable $callback, int $lockFor = 0, int $waitFor = 10, ?string $owner = null): mixed
    {
        return $this->store->lock(enum_value($key), $lockFor, $owner)->block($waitFor, $callback); // @phpstan-ignore method.notFound (lock() is on LockProvider, not Store contract)
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(UnitEnum|string $key): bool
    {
        $key = enum_value($key);

        $this->event(ForgettingKey::class, fn (): ForgettingKey => new ForgettingKey($this->getName(), $key));

        return tap($this->store->forget($this->itemKey($key)), function ($result) use ($key) {
            if ($result) {
                $this->event(KeyForgotten::class, fn (): KeyForgotten => new KeyForgotten($this->getName(), $key));
            } else {
                $this->event(
                    KeyForgetFailed::class,
                    fn (): KeyForgetFailed => new KeyForgetFailed($this->getName(), $key)
                );
            }
        });
    }

    public function delete(UnitEnum|string $key): bool
    {
        return $this->forget($key);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $result = true;

        foreach ($keys as $key) {
            if (! $this->forget($key)) {
                $result = false;
            }
        }

        return $result;
    }

    public function clear(): bool
    {
        $this->event(CacheFlushing::class, fn (): CacheFlushing => new CacheFlushing($this->getName()));

        $result = $this->store->flush();

        if ($result) {
            $this->event(CacheFlushed::class, fn (): CacheFlushed => new CacheFlushed($this->getName()));
        } else {
            $this->event(
                CacheFlushFailed::class,
                fn (): CacheFlushFailed => new CacheFlushFailed($this->getName())
            );
        }

        return $result;
    }

    /**
     * Flush all locks from the cache store.
     *
     * @throws BadMethodCallException
     */
    public function flushLocks(): bool
    {
        $store = $this->getStore();

        if (! $this->supportsFlushingLocks()) {
            throw new BadMethodCallException('This cache store does not support flushing locks.');
        }

        $this->event(CacheLocksFlushing::class, fn (): CacheLocksFlushing => new CacheLocksFlushing($this->getName()));

        $result = $store->flushLocks(); // @phpstan-ignore method.notFound (flushLocks() is on CanFlushLocks, verified by supportsFlushingLocks() above)

        if ($result) {
            $this->event(
                CacheLocksFlushed::class,
                fn (): CacheLocksFlushed => new CacheLocksFlushed($this->getName())
            );
        } else {
            $this->event(
                CacheLocksFlushFailed::class,
                fn (): CacheLocksFlushFailed => new CacheLocksFlushFailed($this->getName())
            );
        }

        return $result;
    }

    /**
     * Begin executing a new tags operation if the store supports it.
     *
     * @throws BadMethodCallException
     */
    public function tags(mixed $names): TaggedCache
    {
        if (! $this->supportsTags()) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        $names = is_array($names) ? $names : func_get_args();
        $names = array_map(fn ($name) => enum_value($name), $names);

        /* @phpstan-ignore-next-line */
        $cache = $this->store->tags($names);

        $cache->config = $this->config;

        if (! is_null($this->events)) {
            $cache->setEventDispatcher($this->events);
        }

        return $cache->setDefaultCacheTime($this->default);
    }

    /**
     * Determine if the current store supports tags.
     */
    public function supportsTags(): bool
    {
        return method_exists($this->store, 'tags');
    }

    /**
     * Determine if the current store supports flushing locks.
     */
    public function supportsFlushingLocks(): bool
    {
        return $this->store instanceof CanFlushLocks;
    }

    /**
     * Get the default cache time.
     */
    public function getDefaultCacheTime(): ?int
    {
        return $this->default;
    }

    /**
     * Set the default cache time in seconds.
     */
    public function setDefaultCacheTime(?int $seconds): static
    {
        $this->default = $seconds;

        return $this;
    }

    /**
     * Get the cache store implementation.
     */
    public function getStore(): Store
    {
        return $this->store;
    }

    /**
     * Set the cache store implementation.
     */
    public function setStore(Store $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): ?Dispatcher
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setEventDispatcher(Dispatcher $events): void
    {
        $this->events = $events;
    }

    /**
     * Get the cache store name.
     */
    public function getName(): ?string
    {
        return $this->config['store'] ?? null;
    }

    /**
     * Determine if a cached value exists.
     *
     * @param string|UnitEnum $key
     */
    public function offsetExists($key): bool
    {
        return $this->has($key);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param string|UnitEnum $key
     */
    public function offsetGet($key): mixed
    {
        return $this->get($key);
    }

    /**
     * Store an item in the cache for the default time.
     *
     * @param string|UnitEnum $key
     * @param mixed $value
     */
    public function offsetSet($key, $value): void
    {
        $this->put($key, $value, $this->default);
    }

    /**
     * Remove an item from the cache.
     *
     * @param string|UnitEnum $key
     */
    public function offsetUnset($key): void
    {
        $this->forget($key);
    }

    /**
     * Handle a result for the "many" method.
     */
    protected function handleManyResult(array $keys, string $key, mixed $value): mixed
    {
        // Events are fired by manyRaw(). This method is a pure default resolver:
        // genuine miss (null) and cached-null (sentinel) both resolve to the
        // per-key default, matching get()'s convention.
        if (is_null($value) || $value === NullSentinel::VALUE) {
            return (isset($keys[$key]) && ! array_is_list($keys)) ? value($keys[$key]) : null;
        }

        return $value;
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

    /**
     * Format the key for a cache item.
     */
    protected function itemKey(string $key): string
    {
        return $key;
    }

    /**
     * Calculate the number of seconds for the given TTL.
     */
    protected function getSeconds(DateInterval|DateTimeInterface|int $ttl): int
    {
        $duration = $this->parseDateInterval($ttl);

        if ($duration instanceof DateTimeInterface) {
            $duration = (int) ceil(
                Carbon::now()->diffInMilliseconds($duration, false) / 1000
            );
        }

        return (int) ($duration > 0 ? $duration : 0);
    }

    /**
     * Fire an event for this cache instance.
     */
    protected function event(string $eventClass, Closure $event): void
    {
        if (! $this->events?->hasListeners($eventClass)) {
            return;
        }

        $this->events->dispatch($event());
    }

    /**
     * Retrieve an item from the cache by key without unwrapping sentinels.
     *
     * @internal For cache-layer internal use (sentinel-aware hit detection in
     *   remember/rememberForever/flexible, plus the RawReadable seam that
     *   wrapper stores like MemoizedStore / FailoverStore use). App code should
     *   use get(), which unwraps NullSentinel::VALUE to null.
     *
     * Fires the same RetrievingKey / CacheHit / CacheMissed events as get(),
     * so observability is unchanged — listeners observing CacheHit may see
     * NullSentinel::VALUE as the event's value field on cached-null entries.
     *
     * Delegates to $this->store->getRaw() when the underlying store implements
     * RawReadable (wrapper stores that need to preserve sentinels across their
     * own internal indirection). Otherwise calls $this->store->get(), which
     * plain stores already implement as a raw read.
     */
    public function getRaw(UnitEnum|string $key): mixed
    {
        $key = enum_value($key);

        $this->event(RetrievingKey::class, fn (): RetrievingKey => new RetrievingKey($this->getName(), $key));

        $value = $this->store instanceof RawReadable
            ? $this->store->getRaw($this->itemKey($key))
            : $this->store->get($this->itemKey($key));

        if (is_null($value)) {
            $this->event(CacheMissed::class, fn (): CacheMissed => new CacheMissed($this->getName(), $key));
        } else {
            $this->event(CacheHit::class, fn (): CacheHit => new CacheHit($this->getName(), $key, NullSentinel::unwrap($value)));
        }

        return $value;
    }

    /**
     * Retrieve multiple items from the cache by key without unwrapping sentinels.
     *
     * @internal For cache-layer internal use. App code should use many(), which
     *   unwraps sentinels via handleManyResult().
     *
     * Batched raw-read counterpart to getRaw(). Used by flexible() to preserve
     * its single batched store read (avoiding a hot-path regression from
     * splitting into sequential get() calls), while still returning raw
     * sentinels so the caller can distinguish "cached sentinel = hit" from
     * "genuinely absent = miss".
     *
     * Applies itemKey() to each key so tag-namespacing works correctly on
     * TaggedCache / AllTaggedCache (which prepend sha1($tagNamespace) . ':').
     * AnyTaggedCache overrides this method to throw, preserving the any-mode
     * invariant that reads through tags are rejected.
     *
     * Fires RetrievingManyKeys + per-key CacheHit/CacheMissed events, matching
     * the event shape of public many() calls.
     *
     * Delegates to $this->store->manyRaw() when the underlying store implements
     * RawReadable (MemoizedStore / FailoverStore). Otherwise calls
     * $this->store->many(), which plain stores already implement as a raw read.
     *
     * @param list<string> $keys
     * @return array<string, mixed> keyed by the input keys (not itemKey-prefixed);
     *                              value may be null (miss), NullSentinel::VALUE (cached-null), or a real value
     */
    public function manyRaw(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $this->event(
            RetrievingManyKeys::class,
            fn (): RetrievingManyKeys => new RetrievingManyKeys($this->getName(), $keys)
        );

        $itemKeys = array_map(fn (string $key): string => $this->itemKey($key), $keys);

        $storeValues = $this->store instanceof RawReadable
            ? $this->store->manyRaw($itemKeys)
            : $this->store->many($itemKeys);

        $result = [];
        foreach ($keys as $i => $key) {
            $value = $storeValues[$itemKeys[$i]] ?? null;
            $result[$key] = $value;

            if (is_null($value)) {
                $this->event(CacheMissed::class, fn (): CacheMissed => new CacheMissed($this->getName(), $key));
            } else {
                $this->event(CacheHit::class, fn (): CacheHit => new CacheHit($this->getName(), $key, NullSentinel::unwrap($value)));
            }
        }

        return $result;
    }

    /**
     * Flush the cache repository's global state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }

    /**
     * Handle dynamic calls into macros or pass missing methods to the store.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->store->{$method}(...$parameters);
    }

    /**
     * Clone cache repository instance.
     */
    public function __clone()
    {
        $this->store = clone $this->store;
    }
}
