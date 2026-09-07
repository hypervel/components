<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

class StackStoreProxy implements Store
{
    public function __construct(protected Store $store, protected ?int $ttl = null)
    {
    }

    /**
     * Get the wrapped store.
     */
    public function getStore(): Store
    {
        return $this->store;
    }

    /**
     * Get the layer TTL override in seconds, if configured.
     */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    public function get(string $key): mixed
    {
        return $this->store->get($key);
    }

    public function many(array $keys): array
    {
        return $this->store->many($keys);
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        if (is_null($this->ttl) || $seconds < $this->ttl) {
            return $this->store->put($key, $value, $seconds);
        }

        return $this->store->put($key, $value, $this->ttl);
    }

    public function putMany(array $values, int $seconds): bool
    {
        if (is_null($this->ttl) || $seconds < $this->ttl) {
            return $this->store->putMany($values, $seconds);
        }

        return $this->store->putMany($values, $this->ttl);
    }

    public function increment(string $key, int $value = 1): bool|int
    {
        return $this->store->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): bool|int
    {
        return $this->store->decrement($key, $value);
    }

    public function forever(string $key, mixed $value): bool
    {
        if (is_null($this->ttl)) {
            return $this->store->forever($key, $value);
        }

        return $this->store->put($key, $value, $this->ttl);
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        if (is_null($this->ttl) || $seconds < $this->ttl) {
            return $this->store->touch($key, $seconds);
        }

        return $this->store->touch($key, $this->ttl);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function flush(): bool
    {
        return $this->store->flush();
    }

    public function getPrefix(): string
    {
        return $this->store->getPrefix();
    }
}
