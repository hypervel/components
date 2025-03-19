<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Contracts\Store;

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
    public function add(string $key, mixed $value, null|DateInterval|DateTimeInterface|int $ttl = null): bool
    {
        $this->tags->addEntry(
            $this->itemKey($key),
            ! is_null($ttl) ? $this->getSeconds($ttl) : 0
        );

        return parent::add($key, $value, $ttl);
    }

    /**
     * Store an item in the cache.
     */
    public function put(array|string $key, mixed $value, null|DateInterval|DateTimeInterface|int $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

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
    public function increment(string $key, int $value = 1): bool|int
    {
        $this->tags->addEntry($this->itemKey($key), updateWhen: 'NX');

        return parent::increment($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): bool|int
    {
        $this->tags->addEntry($this->itemKey($key), updateWhen: 'NX');

        return parent::decrement($key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
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
        foreach ($this->tags->chunkedEntries() as $entries) {
            $keys = array_map(fn (string $key) => $this->store->getPrefix() . $key, $entries);
            $this->store->connection()->del(...$keys);
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
