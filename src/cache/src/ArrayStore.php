<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Support\Carbon;
use Hypervel\Support\InteractsWithTime;
use RuntimeException;

class ArrayStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

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
    public array $locks = [];

    /**
     * Indicates if values are serialized within the store.
     */
    protected bool $serializesValues;

    /**
     * The classes that should be allowed during unserialization.
     */
    protected array|bool|null $serializableClasses;

    /**
     * Create a new Array store.
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
        if ($unserialize === false || $this->serializesValues === false) {
            return $this->storage;
        }

        $storage = [];

        foreach ($this->storage as $key => $data) {
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
        if (! isset($this->storage[$key])) {
            return null;
        }

        $item = $this->storage[$key];

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
        $this->storage[$key] = [
            'value' => $this->serializesValues ? serialize($value) : $value,
            'expiresAt' => $this->calculateExpiration($seconds),
        ];

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        if (! is_null($existing = $this->get($key))) {
            return tap(((int) $existing) + $value, function ($incremented) use ($key) {
                $value = $this->serializesValues ? serialize($incremented) : $incremented;

                $this->storage[$key]['value'] = $value;
            });
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
        $item = $this->storage[$this->getPrefix() . $key] ?? null;

        if (is_null($item)) {
            return false;
        }

        $item['expiresAt'] = $this->calculateExpiration($seconds);

        $this->storage = array_merge($this->storage, [$this->getPrefix() . $key => $item]);

        return true;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        if (array_key_exists($key, $this->storage)) {
            unset($this->storage[$key]);

            return true;
        }

        return false;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->storage = [];

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

        $this->locks = [];

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
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        return true;
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
