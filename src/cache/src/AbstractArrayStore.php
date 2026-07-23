<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\InteractsWithTime;
use RuntimeException;

use function Hypervel\Support\now;

abstract class AbstractArrayStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    /**
     * Indicates if values are serialized within the store.
     */
    protected bool $serializesValues;

    /**
     * The classes that should be allowed during unserialization.
     */
    protected array|bool|null $serializableClasses;

    /**
     * Create a new array-family store.
     */
    public function __construct(bool $serializesValues = false, array|bool|null $serializableClasses = null)
    {
        $this->serializesValues = $serializesValues;
        $this->serializableClasses = $serializableClasses;
    }

    /**
     * Get all of the cached values and their expiration times.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    public function all(bool $unserialize = true): array
    {
        $storage = $this->getCacheItems();

        if ($unserialize === false || $this->serializesValues === false) {
            return $storage;
        }

        foreach ($storage as $key => $data) {
            $storage[$key] = [
                'value' => $this->unserialize($data['value']),
                'expiresAt' => $data['expiresAt'],
            ];
        }

        return $storage;
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        $item = $this->getCacheItem($key);

        if ($item === null) {
            return null;
        }

        $expiresAt = $item['expiresAt'];

        if ($expiresAt !== 0.0 && (now()->getPreciseTimestamp(3) / 1000) >= $expiresAt) {
            $this->forget($key);

            return null;
        }

        return $this->serializesValues ? $this->unserialize($item['value']) : $item['value'];
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->putCacheItem($key, [
            'value' => $this->serializesValues ? serialize($value) : $value,
            'expiresAt' => $this->calculateExpiration($seconds),
        ]);

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        // When backed by WorkerArrayStore, this read/modify/write path is shared across coroutines; keep it non-yielding.
        if (! is_null($existing = $this->get($key))) {
            $incremented = ((int) $existing) + $value;

            /** @var array{value: mixed, expiresAt: float} $item */
            $item = $this->getCacheItem($key);
            $item['value'] = $this->serializesValues ? serialize($incremented) : $incremented;
            $this->putCacheItem($key, $item);

            return $incremented;
        }

        $this->forever($key, $value);

        return $value;
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        $item = $this->getCacheItem($key);

        if ($item === null) {
            return false;
        }

        $item['expiresAt'] = $this->calculateExpiration($seconds);
        $this->putCacheItem($key, $item);

        return true;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return $this->forgetCacheItem($key);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->clearCacheItems();

        return true;
    }

    /**
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
        return true;
    }

    /**
     * Remove all locks from the store.
     *
     * @throws RuntimeException
     */
    public function flushLocks(): bool
    {
        if (! $this->hasSeparateLockStore()) {
            throw new RuntimeException('Flushing locks is only supported when the lock store is separate from the cache store.');
        }

        $this->clearLockRecords();

        return true;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return '';
    }

    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): ArrayLock
    {
        return new ArrayLock($this, $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): ArrayLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        return true;
    }

    /**
     * Get the lock record for the given name.
     *
     * @return null|array{owner: ?string, expiresAt: ?CarbonImmutable}
     */
    abstract public function getLockRecord(string $name): ?array;

    /**
     * Store the lock record for the given name.
     *
     * @param array{owner: ?string, expiresAt: ?CarbonImmutable} $record
     */
    abstract public function putLockRecord(string $name, array $record): void;

    /**
     * Remove the lock record for the given name.
     */
    abstract public function forgetLockRecord(string $name): void;

    /**
     * Remove all lock records.
     */
    abstract public function clearLockRecords(): void;

    /**
     * Get the cached item for the given key.
     *
     * @return null|array{value: mixed, expiresAt: float}
     */
    abstract protected function getCacheItem(string $key): ?array;

    /**
     * Store the cached item for the given key.
     *
     * @param array{value: mixed, expiresAt: float} $item
     */
    abstract protected function putCacheItem(string $key, array $item): void;

    /**
     * Remove the cached item for the given key.
     */
    abstract protected function forgetCacheItem(string $key): bool;

    /**
     * Remove all cached items.
     */
    abstract protected function clearCacheItems(): void;

    /**
     * Get all cached items.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    abstract protected function getCacheItems(): array;

    /**
     * Get the expiration time of the key.
     */
    protected function calculateExpiration(int $seconds): float
    {
        return $this->toTimestamp($seconds);
    }

    /**
     * Get the UNIX timestamp, with milliseconds, for the given number of seconds in the future.
     */
    protected function toTimestamp(int $seconds): float
    {
        return $seconds > 0 ? (now()->getPreciseTimestamp(3) / 1000) + $seconds : 0;
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $value): mixed
    {
        if ($this->serializableClasses !== null) {
            return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
        }

        return unserialize($value);
    }
}
