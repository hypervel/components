<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Contracts\Store;

class RedisTagSet extends TagSet
{
    /**
     * The cache store implementation.
     *
     * @var RedisStore
     */
    protected Store $store;

    /**
     * Add a reference entry to the tag set's underlying sorted set.
     */
    public function addEntry(string $key, int $ttl = 0, ?string $updateWhen = null): void
    {
        $ttl = $ttl > 0 ? now()->addSeconds($ttl)->getTimestamp() : -1;

        foreach ($this->tagIds() as $tagKey) {
            if ($updateWhen) {
                $this->store->connection()->zadd($this->store->getPrefix() . $tagKey, $updateWhen, $ttl, $key);
            } else {
                $this->store->connection()->zadd($this->store->getPrefix() . $tagKey, $ttl, $key);
            }
        }
    }

    /**
     * Get all of the cache entry keys for the tag set.
     */
    public function chunkedEntries(): RedisTaggedCacheChunkedEntries
    {
        return new RedisTaggedCacheChunkedEntries($this->store, $this->tagIds());
    }

    /**
     * Remove the stale entries from the tag set.
     */
    public function flushStaleEntries(): void
    {
        $this->store->connection()->pipeline(function ($pipe) {
            foreach ($this->tagIds() as $tagKey) {
                $pipe->zremrangebyscore($this->store->getPrefix() . $tagKey, '0', (string) now()->getTimestamp());
            }
        });
    }

    /**
     * Flush the tag from the cache.
     */
    public function flushTag(string $name): string
    {
        return $this->resetTag($name);
    }

    /**
     * Reset the tag and return the new tag identifier.
     */
    public function resetTag(string $name): string
    {
        $this->store->forget($this->tagKey($name));

        return $this->tagId($name);
    }

    /**
     * Get the unique tag identifier for a given tag.
     */
    public function tagId(string $name): string
    {
        return "tag:{$name}:entries";
    }

    /**
     * Get the tag identifier key for a given tag.
     */
    public function tagKey(string $name): string
    {
        return "tag:{$name}:entries";
    }
}
