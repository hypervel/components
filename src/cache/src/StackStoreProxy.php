<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;
use RuntimeException;

class StackStoreProxy implements Store
{
    public function __construct(protected Store $store, protected ?int $ttl = null)
    {
    }

    public function get(string $key): mixed
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function many(array $keys): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        if (is_null($this->ttl) || $seconds < $this->ttl) {
            return $this->call(__FUNCTION__, func_get_args());
        }

        return $this->store->put($key, $value, $this->ttl);
    }

    public function putMany(array $values, int $seconds): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function increment(string $key, int $value = 1): bool|int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function decrement(string $key, int $value = 1): bool|int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function forever(string $key, mixed $value): bool
    {
        if (is_null($this->ttl)) {
            return $this->call(__FUNCTION__, func_get_args());
        }

        return $this->store->put($key, $value, $this->ttl);
    }

    public function forget(string $key): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function flush(): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function getPrefix(): string
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    protected function call(string $name, array $arguments): mixed
    {
        if (! method_exists($this->store, $name)) {
            throw new RuntimeException('Method not exist.');
        }

        return $this->store->{$name}(...$arguments);
    }
}
