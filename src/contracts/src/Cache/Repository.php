<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Psr\SimpleCache\CacheInterface;
use UnitEnum;

interface Repository extends CacheInterface
{
    /**
     * Retrieve an item from the cache and delete it.
     *
     * @template TCacheValue
     *
     * @param (Closure(): TCacheValue)|TCacheValue $default
     * @return (TCacheValue is null ? mixed : TCacheValue)
     */
    public function pull(UnitEnum|string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool;

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool;

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int;

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int;

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool;

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function remember(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl, Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function sear(UnitEnum|string $key, Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function rememberForever(UnitEnum|string $key, Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * Unlike remember(), a null return from $callback is stored and returned on
     * subsequent calls rather than triggering re-execution.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function rememberNullable(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl, Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * Unlike rememberForever(), a null return from $callback is stored and returned
     * on subsequent calls rather than triggering re-execution.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function searNullable(UnitEnum|string $key, Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * Unlike rememberForever(), a null return from $callback is stored and returned
     * on subsequent calls rather than triggering re-execution.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function rememberForeverNullable(UnitEnum|string $key, Closure $callback): mixed;

    /**
     * Set the expiration of a cached item; null TTL will retain the item forever.
     */
    public function touch(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl = null): bool;

    /**
     * Remove an item from the cache.
     */
    public function forget(UnitEnum|string $key): bool;

    /**
     * Get the cache store implementation.
     */
    public function getStore(): Store;
}
