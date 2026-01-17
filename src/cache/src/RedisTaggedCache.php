<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Contracts\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

class RedisTaggedCache extends TaggedCache
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
     * @var RedisTagSet
     */
    protected TagSet $tags;

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(BackedEnum|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = enum_value($key);

        $this->tags->addEntry(
            $this->itemKey($key),
            ! is_null($ttl) ? $this->getSeconds($ttl) : 0
        );

        return parent::add($key, $value, $ttl);
    }

    /**
     * Store an item in the cache.
     */
    public function put(array|BackedEnum|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = enum_value($key);

        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        $this->tags->addEntry(
            $this->itemKey($key),
            $this->getSeconds($ttl)
        );

        return parent::put($key, $value, $ttl);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(BackedEnum|UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = enum_value($key);

        $this->tags->addEntry($this->itemKey($key), updateWhen: 'NX');

        return parent::increment($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(BackedEnum|UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = enum_value($key);

        $this->tags->addEntry($this->itemKey($key), updateWhen: 'NX');

        return parent::decrement($key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(BackedEnum|UnitEnum|string $key, mixed $value): bool
    {
        $key = enum_value($key);

        $this->tags->addEntry($this->itemKey($key));

        return parent::forever($key, $value);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->flushValues();
        $this->tags->flush();

        return true;
    }

    /**
     * Flush the individual cache entries for the tags.
     */
    protected function flushValues(): void
    {
        $entries = $this->tags->entries()
            ->map(fn (string $key) => $this->store->getPrefix() . $key)
            ->chunk(1000);

        foreach ($entries as $cacheKeys) {
            $this->store->connection()->del(...$cacheKeys);
        }
    }

    /**
     * Remove all stale reference entries from the tag set.
     */
    public function flushStale(): bool
    {
        $this->tags->flushStaleEntries();

        return true;
    }
}
