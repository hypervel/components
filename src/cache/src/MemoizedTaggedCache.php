<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use DateInterval;
use DateTimeInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Values read through a namespaced tag set are memoized by this instance.
 * Any-mode tagged items live under plain keys, so they share the memoized
 * store's values instead.
 */
class MemoizedTaggedCache extends TaggedCache
{
    /**
     * The memoized cache values.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * The tagged cache instance.
     */
    protected TaggedCache $taggedCache;

    /**
     * The memoized store instance.
     */
    protected MemoizedStore $memoizedStore;

    /**
     * Create a new memoized tagged cache.
     */
    public function __construct(TaggedCache $taggedCache, MemoizedStore $memoizedStore)
    {
        $this->taggedCache = $taggedCache;
        $this->memoizedStore = $memoizedStore;

        parent::__construct($taggedCache->getStore(), $taggedCache->getTags());

        $this->config = ['store' => $taggedCache->getName()];
        $this->default = $taggedCache->getDefaultCacheTime();

        if (! is_null($taggedCache->getEventDispatcher())) {
            $this->setEventDispatcher($taggedCache->getEventDispatcher());
        }
    }

    /**
     * Retrieve an item from the cache without unwrapping sentinels.
     */
    public function getRaw(UnitEnum|string $key): mixed
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $prefixedKey = $this->prefix($key);

        if (! array_key_exists($prefixedKey, $this->cache)) {
            $this->cache[$prefixedKey] = $this->taggedCache->getRaw($key);
        }

        return $this->cache[$prefixedKey];
    }

    /**
     * Retrieve an item without serving it from the memoized values.
     */
    public function getAuthoritativeRaw(UnitEnum|string $key): mixed
    {
        return $this->taggedCache->getAuthoritativeRaw($key);
    }

    /**
     * Retrieve multiple items from the cache without unwrapping sentinels.
     */
    public function manyRaw(array $keys): array
    {
        [$memoized, $missing] = [[], []];

        foreach ($keys as $key) {
            $stringKey = (string) $key;
            $prefixedKey = $this->prefix($stringKey);

            if (array_key_exists($prefixedKey, $this->cache)) {
                $memoized[$stringKey] = $this->cache[$prefixedKey];
            } else {
                $missing[] = $stringKey;
            }
        }

        $retrieved = [];

        if ($missing !== []) {
            $retrieved = $this->taggedCache->manyRaw($missing);

            foreach ($retrieved as $key => $value) {
                $this->cache[$this->prefix((string) $key)] = $value;
            }
        }

        $result = [];

        foreach ($keys as $key) {
            $stringKey = (string) $key;
            $result[$stringKey] = $memoized[$stringKey] ?? $retrieved[$stringKey] ?? null;
        }

        return $result;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->put($key, $value, $ttl);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->forgetMemoized((string) $key);
        }

        return $this->taggedCache->putMany($values, $ttl);
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->add($key, $value, $ttl);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->increment($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->decrement($key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->forever($key, $value);
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(UnitEnum|string $key, DateInterval|DateTimeInterface|int $ttl): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->touch($key, $ttl);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(array|UnitEnum|string $key): bool
    {
        if (is_array($key)) {
            return $this->deleteMultiple($key);
        }

        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->forgetMemoized($key);

        return $this->taggedCache->forget($key);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        if ($this->taggedCache instanceof AnyModeTaggedCache) {
            $this->memoizedStore->flushMemoized();
        } else {
            $this->memoizedStore->flushTagged();
        }

        return $this->taggedCache->flush();
    }

    /**
     * Remove all memoized items from the cache.
     */
    public function flushMemoized(): void
    {
        $this->cache = [];
    }

    /**
     * Retrieve an item for remember operations without unwrapping sentinels.
     */
    protected function getRawForRemember(UnitEnum|string $key): mixed
    {
        if (! $this->taggedCache instanceof AnyModeTaggedCache) {
            return $this->getRaw($key);
        }

        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->memoizedStore->memoize(
            $key,
            fn (): mixed => $this->taggedCache->getRawForRemember($key)
        );
    }

    /**
     * Forget the memoized value for the given key.
     */
    protected function forgetMemoized(string $key): void
    {
        if ($this->taggedCache instanceof AnyModeTaggedCache) {
            $this->memoizedStore->forgetMemoized($key);

            return;
        }

        unset($this->cache[$this->prefix($key)]);
    }

    /**
     * Format the key for a cache item.
     */
    protected function itemKey(string $key): string
    {
        return $this->taggedCache->itemKey($key);
    }

    /**
     * Prefix the given key.
     */
    protected function prefix(string $key): string
    {
        // Each instance serves one ordered tag list, so memoized keys omit the
        // tag namespace. Resolving it can read every tag version from the store.
        return $this->store->getPrefix() . $key;
    }

    /**
     * Handle dynamic calls into macros or pass missing methods to the tagged cache.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->taggedCache->{$method}(...$parameters);
    }
}
