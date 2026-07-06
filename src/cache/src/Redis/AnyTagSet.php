<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis;

use Generator;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\TagSet;
use Hypervel\Contracts\Cache\Store;

/**
 * Any-mode tag set for Redis 8.0+ enhanced tagging.
 *
 * Tags are identified by their names, and hashes track membership with
 * HSETEX field expiration. Flush deletes the actual cache keys written
 * with any of the specified tags.
 */
class AnyTagSet extends TagSet
{
    /**
     * The cache store implementation.
     *
     * @var RedisStore
     */
    protected Store $store;

    /**
     * Create a new AnyTagSet instance.
     */
    public function __construct(RedisStore $store, array $names = [])
    {
        parent::__construct($store, $names);
    }

    /**
     * Get the hash key for a tag.
     *
     * Delegates to StoreContext which delegates to TagMode (single source of truth).
     * Format: "{prefix}_any:tag:{tag}:entries"
     */
    public function tagHashKey(string $name): string
    {
        return $this->getRedisStore()->getContext()->tagHashKey($name);
    }

    /**
     * Get all cache keys for this tag set (union of all tags).
     *
     * This is a generator that yields unique keys across all tags.
     * Used for listing tagged items or bulk operations.
     */
    public function entries(): Generator
    {
        $seen = [];

        foreach ($this->names as $name) {
            foreach ($this->getRedisStore()->anyTagOps()->getTaggedKeys()->execute($name) as $key) {
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    yield $key;
                }
            }
        }
    }

    /**
     * Reset the tag set.
     *
     * In any mode, this deletes the cached items themselves, unlike
     * namespaced tag sets where reset only invalidates tag tracking.
     */
    public function reset(): void
    {
        $this->flush();
    }

    /**
     * Flush all tags in this set.
     *
     * Deletes all cache items that have ANY of the specified tags
     * (union semantics), along with their reverse indexes and tag hashes.
     */
    public function flush(): void
    {
        $this->getRedisStore()->anyTagOps()->flush()->execute($this->names);
    }

    /**
     * Flush a single tag.
     */
    public function flushTag(string $name): string
    {
        $this->getRedisStore()->anyTagOps()->flush()->execute([$name]);

        return $this->tagHashKey($name);
    }

    /**
     * Get the store as a RedisStore instance.
     */
    protected function getRedisStore(): RedisStore
    {
        return $this->store;
    }
}
