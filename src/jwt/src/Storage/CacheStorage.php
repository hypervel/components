<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Storage;

use Hypervel\Cache\TaggableStore;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Jwt\Contracts\StorageContract;

class CacheStorage implements StorageContract
{
    /**
     * Prefix that keeps blacklist keys apart from other cache keys.
     */
    private const string KEY_PREFIX = 'jwt_blacklist:';

    protected string $tag = 'jwt_blacklist';

    /**
     * Whether writes go through the blacklist tag so it can be flushed.
     */
    protected bool $writesThroughTags = false;

    /**
     * Whether reads and deletes go through the blacklist tag.
     *
     * All-mode tags namespace the stored key, so every access must use them.
     * Any-mode tags only index writes for flushing, so reads and deletes use
     * the plain key, as they do on stores without tags.
     */
    protected bool $readsThroughTags = false;

    /**
     * Create a new cache storage instance.
     */
    public function __construct(
        protected CacheContract $cache
    ) {
        $store = $cache->getStore();

        if ($store instanceof TaggableStore && $store->supportsTags()) {
            $this->writesThroughTags = true;
            $this->readsThroughTags = ! $store->getTagMode()->supportsDirectGet();
        }
    }

    /**
     * Add a new item into storage.
     */
    public function add(string $key, mixed $value, int $minutes): bool
    {
        return $this->writeCache()->put($this->storageKey($key), $value, $minutes * 60);
    }

    /**
     * Add a new item into storage forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->writeCache()->forever($this->storageKey($key), $value);
    }

    /**
     * Get an item from storage.
     */
    public function get(string $key): mixed
    {
        return $this->readCache()->get($this->storageKey($key));
    }

    /**
     * Remove an item from storage.
     */
    public function destroy(string $key): bool
    {
        return $this->readCache()->forget($this->storageKey($key));
    }

    /**
     * Remove all items associated with the tag.
     *
     * Only a store with tag support can clear the blacklist without removing
     * other cache entries, so on any other store the cache's tags() call throws.
     */
    public function flush(): bool
    {
        // @phpstan-ignore method.notFound
        return $this->cache->tags([$this->tag])->flush();
    }

    /**
     * Get the cache that blacklist writes go through.
     */
    protected function writeCache(): CacheContract
    {
        // @phpstan-ignore method.notFound
        return $this->writesThroughTags ? $this->cache->tags([$this->tag]) : $this->cache;
    }

    /**
     * Get the cache that blacklist reads and deletes go through.
     */
    protected function readCache(): CacheContract
    {
        // @phpstan-ignore method.notFound
        return $this->readsThroughTags ? $this->cache->tags([$this->tag]) : $this->cache;
    }

    /**
     * Get the cache key for a logical blacklist key.
     */
    protected function storageKey(string $key): string
    {
        // Hashing bounds application-chosen identifiers to fit limited key columns,
        // such as the database store's. SHA-256 keeps distinct identifiers apart.
        return self::KEY_PREFIX . hash('sha256', $key);
    }
}
