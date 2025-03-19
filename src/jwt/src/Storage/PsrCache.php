<?php

declare(strict_types=1);

namespace Hypervel\JWT\Storage;

use Hypervel\JWT\Contracts\StorageContract;
use Psr\SimpleCache\CacheInterface;

class PsrCache implements StorageContract
{
    /**
     * Constructor.
     */
    public function __construct(
        protected CacheInterface $cache
    ) {
    }

    /**
     * Add a new item into storage.
     */
    public function add(string $key, mixed $value, int $minutes): void
    {
        $this->cache->set($key, $value, $minutes * 60);
    }

    /**
     * Add a new item into storage forever.
     */
    public function forever(string $key, mixed $value): void
    {
        $this->cache->set($key, $value);
    }

    /**
     * Get an item from storage.
     */
    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    /**
     * Remove an item from storage.
     */
    public function destroy(string $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * Remove all items associated with the tag.
     */
    public function flush(): void
    {
        $this->cache->clear();
    }
}
