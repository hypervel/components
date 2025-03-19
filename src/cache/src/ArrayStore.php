<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Cache\Contracts\LockProvider;

class ArrayStore extends TaggableStore implements LockProvider
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    /**
     * The array of locks.
     */
    public array $locks = [];

    /**
     * The array of stored values.
     */
    protected array $storage = [];

    /**
     * Indicates if values are serialized within the store.
     */
    protected bool $serializesValues;

    /**
     * Create a new Array store.
     */
    public function __construct(bool $serializesValues = false)
    {
        $this->serializesValues = $serializesValues;
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

        $expiresAt = $item['expiresAt'] ?? 0.0;

        if ($expiresAt !== 0.0 && (now()->getPreciseTimestamp(3) / 1000) >= $expiresAt) {
            $this->forget($key);

            return null;
        }

        return $this->serializesValues ? unserialize($item['value']) : $item['value'];
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
}
