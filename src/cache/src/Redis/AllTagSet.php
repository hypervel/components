<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis;

use Hyperf\Collection\LazyCollection;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\TagSet;

class AllTagSet extends TagSet
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
        $this->store->allTagOps()->addEntry()->execute($key, $ttl, $this->tagIds(), $updateWhen);
    }

    /**
     * Get all of the cache entry keys for the tag set.
     */
    public function entries(): LazyCollection
    {
        return $this->store->allTagOps()->getEntries()->execute($this->tagIds());
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
     *
     * Delegates to StoreContext which delegates to TagMode (single source of truth).
     * Format: "_all:tag:{name}:entries"
     */
    public function tagId(string $name): string
    {
        return $this->store->getContext()->tagId($name);
    }

    /**
     * Get the tag identifier key for a given tag.
     *
     * Same as tagId() - the identifier without cache prefix.
     * Used with store->forget() which adds the prefix.
     */
    public function tagKey(string $name): string
    {
        return $this->store->getContext()->tagId($name);
    }
}
