<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BadMethodCallException;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyRetrievalFailed;
use Hypervel\Cache\Events\RetrievingKey;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

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
     * Retrieve an item from the cache without unwrapping sentinels.
     *
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
     * Retrieve an item without serving it from a non-authoritative read layer.
     *
     * @throws BadMethodCallException always - tags are for writing and flushing only
     */
    public function getAuthoritativeRaw(UnitEnum|string $key): mixed
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
     * Retrieve multiple items from the cache without unwrapping sentinels.
     *
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

    /**
     * Retrieve a plain-key item without exposing reads through the any-mode API.
     */
    protected function getPlainRaw(UnitEnum|string $key): mixed
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        if ($this->events?->hasListeners(RetrievingKey::class)) {
            $this->event(new RetrievingKey($this->getName(), $key));
        }

        try {
            $value = $this->handleIncompleteClass($key, $this->store->get($key));
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->events?->hasListeners(KeyRetrievalFailed::class)) {
                $this->event(new KeyRetrievalFailed($this->getName(), $key, $exception));
            }

            throw $exception;
        }

        if (is_null($value)) {
            if ($this->events?->hasListeners(CacheMissed::class)) {
                $this->event(new CacheMissed($this->getName(), $key));
            }
        } elseif ($this->events?->hasListeners(CacheHit::class)) {
            $this->event(new CacheHit($this->getName(), $key, NullSentinel::unwrap($value)));
        }

        return $value;
    }

    /**
     * Retrieve a plain-key item for remember operations.
     */
    protected function getRawForRemember(UnitEnum|string $key): mixed
    {
        return $this->getPlainRaw($key);
    }

    /**
     * Remove a plain-key item without exposing deletes through the any-mode API.
     */
    protected function forgetPlainKey(UnitEnum|string $key): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        if ($this->events?->hasListeners(ForgettingKey::class)) {
            $this->event(new ForgettingKey($this->getName(), $key));
        }

        try {
            $result = $this->store->forget($key);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->events?->hasListeners(KeyForgetFailed::class)) {
                $this->event(new KeyForgetFailed($this->getName(), $key, exception: $exception));
            }

            throw $exception;
        }

        if ($result) {
            if ($this->events?->hasListeners(KeyForgotten::class)) {
                $this->event(new KeyForgotten($this->getName(), $key));
            }
        } elseif ($this->events?->hasListeners(KeyForgetFailed::class)) {
            $this->event(new KeyForgetFailed($this->getName(), $key));
        }

        return $result;
    }
}
