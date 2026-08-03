<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Context\CoroutineContext;
use Hypervel\Support\CarbonImmutable;

class ArrayStore extends AbstractArrayStore
{
    /**
     * The context key prefix for stored values.
     */
    protected const STORAGE_CONTEXT_KEY_PREFIX = '__cache.array.storage.';

    /**
     * The context key prefix for lock records.
     */
    protected const LOCKS_CONTEXT_KEY_PREFIX = '__cache.array.locks.';

    /**
     * The sequence used to build unique per-instance context keys.
     */
    // Do not reset this; live context buckets may still use earlier suffixes.
    private static int $contextKeySequence = 0;

    /**
     * The coroutine-local key for this store's values.
     */
    protected readonly string $storageContextKey;

    /**
     * The coroutine-local key for this store's lock records.
     */
    protected readonly string $locksContextKey;

    /**
     * Create a new Array store.
     */
    public function __construct(
        bool $serializesValues = false,
        array|bool|null $serializableClasses = null,
        ?SerializableClassPolicy $serializableClassPolicy = null,
    ) {
        parent::__construct($serializesValues, $serializableClasses, $serializableClassPolicy);

        $suffix = (string) ++self::$contextKeySequence;

        $this->storageContextKey = self::STORAGE_CONTEXT_KEY_PREFIX . $suffix;
        $this->locksContextKey = self::LOCKS_CONTEXT_KEY_PREFIX . $suffix;
    }

    /**
     * Get the cached item for the given key.
     *
     * @return null|array{value: mixed, expiresAt: float}
     */
    protected function getCacheItem(string $key): ?array
    {
        return $this->getCacheItems()[$key] ?? null;
    }

    /**
     * Store the cached item for the given key.
     *
     * @param array{value: mixed, expiresAt: float} $item
     */
    protected function putCacheItem(string $key, array $item): void
    {
        $items = $this->getCacheItems();
        $items[$key] = $item;

        CoroutineContext::set($this->storageContextKey, $items);
    }

    /**
     * Remove the cached item for the given key.
     */
    protected function forgetCacheItem(string $key): bool
    {
        $items = $this->getCacheItems();

        if (! array_key_exists($key, $items)) {
            return false;
        }

        unset($items[$key]);
        CoroutineContext::set($this->storageContextKey, $items);

        return true;
    }

    /**
     * Remove all cached items.
     */
    protected function clearCacheItems(): void
    {
        CoroutineContext::set($this->storageContextKey, []);
    }

    /**
     * Get all cached items.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    protected function getCacheItems(): array
    {
        return CoroutineContext::get($this->storageContextKey, []);
    }

    /**
     * Get the lock record for the given name.
     *
     * @return null|array{owner: ?string, expiresAt: ?CarbonImmutable}
     */
    public function getLockRecord(string $name): ?array
    {
        return $this->getLockRecords()[$name] ?? null;
    }

    /**
     * Store the lock record for the given name.
     *
     * @param array{owner: ?string, expiresAt: ?CarbonImmutable} $record
     */
    public function putLockRecord(string $name, array $record): void
    {
        $records = $this->getLockRecords();
        $records[$name] = $record;

        CoroutineContext::set($this->locksContextKey, $records);
    }

    /**
     * Remove the lock record for the given name.
     */
    public function forgetLockRecord(string $name): void
    {
        $records = $this->getLockRecords();

        unset($records[$name]);

        CoroutineContext::set($this->locksContextKey, $records);
    }

    /**
     * Remove all lock records.
     */
    public function clearLockRecords(): void
    {
        CoroutineContext::set($this->locksContextKey, []);
    }

    /**
     * Get all lock records.
     *
     * @return array<string, array{owner: ?string, expiresAt: ?CarbonImmutable}>
     */
    protected function getLockRecords(): array
    {
        return CoroutineContext::get($this->locksContextKey, []);
    }
}
