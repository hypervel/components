<?php

declare(strict_types=1);

namespace Hypervel\JWT\Storage;

use Hypervel\Cache\TaggableStore;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\JWT\Contracts\StorageContract;

class TaggedCache implements StorageContract
{
    /**
     * Key prefix applied in direct-key storage.
     *
     * In all mode the tag namespace isolates blacklist keys from the rest
     * of the cache; in any mode keys are plain, so the prefix provides
     * that isolation instead.
     */
    private const DIRECT_KEY_PREFIX = 'jwt_blacklist:';

    protected string $tag = 'jwt_blacklist';

    /**
     * Whether the store uses direct plain-key reads.
     *
     * Any-mode tags are write/index/flush only: writes go through tags()
     * to record the invalidation index, while reads and per-key deletes
     * use the plain key.
     */
    protected bool $directKeyMode;

    /**
     * Constructor.
     */
    public function __construct(
        protected CacheContract $cache
    ) {
        $store = $cache->getStore();

        $this->directKeyMode = $store instanceof TaggableStore
            && $store->supportsTags()
            && $store->getTagMode()->supportsDirectGet();
    }

    /**
     * Add a new item into storage.
     */
    public function add(string $key, mixed $value, int $minutes): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->tags([$this->tag])->put($this->storageKey($key), $value, $minutes * 60);
    }

    /**
     * Add a new item into storage forever.
     */
    public function forever(string $key, mixed $value): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->tags([$this->tag])->forever($this->storageKey($key), $value);
    }

    /**
     * Get an item from storage.
     */
    public function get(string $key): mixed
    {
        if ($this->directKeyMode) {
            return $this->cache->get($this->storageKey($key));
        }

        /* @phpstan-ignore-next-line */
        return $this->cache->tags([$this->tag])->get($key);
    }

    /**
     * Remove an item from storage.
     */
    public function destroy(string $key): bool
    {
        if ($this->directKeyMode) {
            return $this->cache->forget($this->storageKey($key));
        }

        /* @phpstan-ignore-next-line */
        return $this->cache->tags([$this->tag])->forget($key);
    }

    /**
     * Remove all items associated with the tag.
     */
    public function flush(): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->tags([$this->tag])->flush();
    }

    /**
     * Get the storage key for a logical blacklist key.
     */
    protected function storageKey(string $key): string
    {
        return $this->directKeyMode ? self::DIRECT_KEY_PREFIX . $key : $key;
    }
}
