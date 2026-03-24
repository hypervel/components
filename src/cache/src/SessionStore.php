<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Session\Session;
use Hypervel\Support\Carbon;
use Hypervel\Support\InteractsWithTime;

class SessionStore implements Store
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    /**
     * Create a new session cache store.
     */
    public function __construct(
        public Session $session,
        public string $key = '_cache',
    ) {
    }

    /**
     * Get all of the cached values and their expiration times.
     *
     * @return array<string, array{value: mixed, expiresAt: float}>
     */
    public function all(): array
    {
        return $this->session->get($this->key, []);
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        if (! $this->session->exists($this->itemKey($key))) {
            return null;
        }

        $item = $this->session->get($this->itemKey($key));

        $expiresAt = $item['expiresAt'] ?? 0.0;

        if ($this->isExpired($expiresAt)) {
            $this->forget($key);

            return null;
        }

        return $item['value'];
    }

    /**
     * Determine if the given expiration time is expired.
     */
    protected function isExpired(int|float $expiresAt): bool
    {
        return $expiresAt > 0 && (Carbon::now()->getPreciseTimestamp(3) / 1000) >= $expiresAt;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $this->session->put($this->itemKey($key), [
            'value' => $value,
            'expiresAt' => $this->toTimestamp($seconds),
        ]);

        return true;
    }

    /**
     * Get the UNIX timestamp, with milliseconds, for the given number of seconds in the future.
     */
    protected function toTimestamp(int $seconds): float
    {
        return $seconds > 0 ? (Carbon::now()->getPreciseTimestamp(3) / 1000) + $seconds : 0.0;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        if (! is_null($existing = $this->get($key))) {
            return tap(((int) $existing) + $value, function ($incremented) use ($key) {
                $this->session->put($this->itemKey("{$key}.value"), $incremented);
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
        $value = $this->get($key);

        if (is_null($value)) {
            return false;
        }

        $this->put($key, $value, $seconds);

        return true;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        if ($this->session->exists($this->itemKey($key))) {
            $this->session->forget($this->itemKey($key));

            return true;
        }

        return false;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->session->put($this->key, []);

        return true;
    }

    /**
     * Get the item key for the given key.
     */
    public function itemKey(string $key): string
    {
        return "{$this->key}.{$key}";
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return '';
    }
}
