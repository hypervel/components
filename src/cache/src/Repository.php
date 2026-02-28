<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use ArrayAccess;
use BadMethodCallException;
use Carbon\Carbon;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
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
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Contracts\Cache\Store
 */
class Repository implements ArrayAccess, CacheContract
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

        $key = enum_value($key);

        $this->event(new RetrievingKey($this->getName(), $key));

        $value = $this->store->get($this->itemKey($key));

        // If we could not find the cache value, we will fire the missed event and get
        // the default value for this cache value. This default could be a callback
        // so we will execute the value function which will resolve it if needed.
        if (is_null($value)) {
            $this->event(new CacheMissed($this->getName(), $key));

            $value = value($default);
        } else {
            $this->event(new CacheHit($this->getName(), $key, $value));
        }

        return $value;
    }

    /**
     * Retrieve multiple items from the cache by key.
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        $this->event(new RetrievingManyKeys($this->getName(), $keys));

        $values = $this->store->many(
            collect($keys)->map(function ($value, $key) {
                return is_string($key) ? $key : $value;
            })->values()->all()
        );

        return collect($values)->map(function ($value, $key) use ($keys) {
            return $this->handleManyResult($keys, $key, $value);
        })->all();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $defaults = [];

        foreach ($keys as $key) {
            $defaults[$key] = $default;
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

        $this->event(new WritingKey($this->getName(), $key, $value, $seconds));

        $result = $this->store->put($this->itemKey($key), $value, $seconds);
        if ($result) {
            $this->event(new KeyWritten($this->getName(), $key, $value, $seconds));
        } else {
            $this->event(new KeyWriteFailed($this->getName(), $key, $value, $seconds));
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
            return $this->deleteMultiple(array_keys($values));
        }

        $this->event(new WritingManyKeys($this->getName(), array_keys($values), array_values($values), $seconds));

        $result = $this->store->putMany($values, $seconds);

        foreach ($values as $key => $value) {
            if ($result) {
                $this->event(new KeyWritten($this->getName(), $key, $value, $seconds));
            } else {
                $this->event(new KeyWriteFailed($this->getName(), $key, $value, $seconds));
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

        $this->event(new WritingKey($this->getName(), $key, $value));

        $result = $this->store->forever($this->itemKey($key), $value);

        if ($result) {
            $this->event(new KeyWritten($this->getName(), $key, $value));
        } else {
            $this->event(new KeyWriteFailed($this->getName(), $key, $value));
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
        // Use optimized single-connection path for RedisStore
        if ($this->store instanceof RedisStore) {
            return $this->store->remember(
                $this->itemKey($key),
                $this->getSeconds($ttl),
                $callback
            );
        }

        $value = $this->get($key);

        // If the item exists in the cache we will just return this immediately and if
        // not we will execute the given Closure and cache the result of that for a
        // given number of seconds so it's available for all subsequent requests.
        if (! is_null($value)) {
            return $value;
        }

        $this->put($key, $value = $callback(), $ttl);

        return $value;
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
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     *
     * @return TCacheValue
     */
    public function rememberForever(UnitEnum|string $key, Closure $callback): mixed
    {
        // Use optimized single-connection path for RedisStore
        if ($this->store instanceof RedisStore) {
            [$value, $wasHit] = $this->store->rememberForever(
                $this->itemKey($key),
                $callback
            );

            if ($wasHit) {
                $this->event(new CacheHit($this->getName(), $key, $value));
            } else {
                $this->event(new CacheMissed($this->getName(), $key));
                $this->event(new KeyWritten($this->getName(), $key, $value));
            }

            return $value;
        }

        $value = $this->get($key);

        // If the item exists in the cache we will just return this immediately
        // and if not we will execute the given Closure and cache the result
        // of that forever so it is available for all subsequent requests.
        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(UnitEnum|string $key): bool
    {
        $key = enum_value($key);

        $this->event(new ForgettingKey($this->getName(), $key));

        return tap($this->store->forget($this->itemKey($key)), function ($result) use ($key) {
            if ($result) {
                $this->event(new KeyForgotten($this->getName(), $key));
            } else {
                $this->event(new KeyForgetFailed($this->getName(), $key));
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
        $this->event(new CacheFlushing($this->getName()));

        $result = $this->store->flush();

        if ($result) {
            $this->event(new CacheFlushed($this->getName()));
        } else {
            $this->event(new CacheFlushFailed($this->getName()));
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
        // If we could not find the cache value, we will fire the missed event and get
        // the default value for this cache value. This default could be a callback
        // so we will execute the value function which will resolve it if needed.
        if (is_null($value)) {
            $this->event(new CacheMissed($this->getName(), $key));

            return (isset($keys[$key]) && ! array_is_list($keys)) ? value($keys[$key]) : null;
        }

        // If we found a valid value we will fire the "hit" event and return the value
        // back from this function. The "hit" event gives developers an opportunity
        // to listen for every possible cache "hit" throughout this applications.
        $this->event(new CacheHit($this->getName(), $key, $value));

        return $value;
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
            $duration = Carbon::now()->diffInRealSeconds($duration, false);
        }

        return (int) $duration > 0 ? $duration : 0;
    }

    /**
     * Fire an event for this cache instance.
     */
    protected function event(object $event): void
    {
        $this->events?->dispatch($event);
    }
}
