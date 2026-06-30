<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Support\Carbon;

class WorkerArrayStore extends AbstractArrayStore
{
    /**
     * The array of stored values.
     *
     * @var array<string, array{value: mixed, expiresAt: float}>
     */
    protected array $storage = [];

    /**
     * The array of locks.
     *
     * @var array<string, array{owner: ?string, expiresAt: ?Carbon}>
     */
    protected array $locks = [];

    /**
     * Get the cached item for the given key.
     *
     * @return null|array{value: mixed, expiresAt: float}
     */
    protected function getCacheItem(string $key): ?array
    {
        return $this->storage[$key] ?? null;
    }

    /**
     * Store the cached item for the given key.
     *
     * @param array{value: mixed, expiresAt: float} $item
     */
    protected function putCacheItem(string $key, array $item): void
    {
        $this->storage[$key] = $item;
    }

    /**
     * Remove the cached item for the given key.
     */
    protected function forgetCacheItem(string $key): bool
    {
        if (! array_key_exists($key, $this->storage)) {
            return false;
        }

        unset($this->storage[$key]);

        return true;
    }

    /**
     * Remove all cached items.
     */
    protected function clearCacheItems(): void
    {
        $this->storage = [];
    }

    /**
     * Get all cached items.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    protected function getCacheItems(): array
    {
        return $this->storage;
    }

    /**
     * Get the lock record for the given name.
     *
     * @return null|array{owner: ?string, expiresAt: ?Carbon}
     */
    public function getLockRecord(string $name): ?array
    {
        return $this->locks[$name] ?? null;
    }

    /**
     * Store the lock record for the given name.
     *
     * @param array{owner: ?string, expiresAt: ?Carbon} $record
     */
    public function putLockRecord(string $name, array $record): void
    {
        $this->locks[$name] = $record;
    }

    /**
     * Remove the lock record for the given name.
     */
    public function forgetLockRecord(string $name): void
    {
        unset($this->locks[$name]);
    }

    /**
     * Remove all lock records.
     */
    public function clearLockRecords(): void
    {
        $this->locks = [];
    }
}
