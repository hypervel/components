<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Carbon\Carbon;
use Closure;
use Hypervel\Contracts\Cache\Store;

class StackStore implements Store
{
    /**
     * @param Store[] $stores
     */
    public function __construct(protected array $stores)
    {
    }

    public function get(string $key): mixed
    {
        $record = $this->getOrRestoreRecord($key);

        return $record['value'] ?? null;
    }

    public function many(array $keys): array
    {
        return array_map(fn ($key) => $this->get($key), array_combine($keys, $keys));
    }

    public function put(string $key, mixed $value, int $seconds): bool
    {
        $record = [
            'value' => $value,
            'ttl' => $seconds,
        ];

        return $this->putRecord($key, $record);
    }

    public function putMany(array $values, int $seconds): bool
    {
        foreach ($values as $key => $value) {
            if (! $this->put($key, $value, $seconds)) {
                return false;
            }
        }

        return true;
    }

    public function increment(string $key, int $value = 1): bool|int
    {
        $record = $this->getOrRestoreRecord($key);

        if (is_null($record)) {
            return tap($value, fn ($value) => $this->forever($key, $value));
        }

        $newValue = $record['value'] + $value;
        $newRecord = ['value' => $newValue] + $record;

        if ($this->putRecord($key, $newRecord)) {
            return $newValue;
        }

        return false;
    }

    public function decrement(string $key, int $value = 1): bool|int
    {
        return $this->increment($key, $value * -1);
    }

    public function forever(string $key, mixed $value): bool
    {
        $record = compact('value');

        return $this->callStores(
            fn (Store $store) => $store->forever($key, $record),
            fn (Store $store) => $store->forget($key),
        );
    }

    public function forget(string $key): bool
    {
        return $this->callStores(
            fn (Store $store) => $store->forget($key),
            force: true
        );
    }

    public function flush(): bool
    {
        return $this->callStores(
            static fn (Store $store) => $store->flush(),
            force: true
        );
    }

    public function getPrefix(): string
    {
        return '';
    }

    protected function getOrRestoreRecord(string $key): mixed
    {
        return $this->callStoresStacked(
            function (Store $store, Closure $next) use ($key): ?array {
                if (! is_null($record = $store->get($key))) {
                    return (array) $record;
                }

                if (is_null($record = $next())) {
                    return null;
                }

                if ($this->putToStore($store, $key, $record)) {
                    return $record;
                }

                return null;
            },
            static fn () => null
        );
    }

    protected function putRecord(string $key, array $record): bool
    {
        return $this->callStores(
            fn (Store $store) => $this->putToStore($store, $key, $record),
            fn (Store $store) => $store->forget($key),
        );
    }

    protected function putToStore(Store $store, string $key, array $record): bool
    {
        if (! array_key_exists('value', $record)) {
            return false;
        }

        if (! array_key_exists('expiration', $record) && ! array_key_exists('ttl', $record)) {
            return $store->forever($key, $record);
        }

        $currentTimestamp = Carbon::now()->getTimestamp();
        $value = $record['value'];
        $expiration = $record['expiration'] ?? $currentTimestamp + $record['ttl'];
        $ttl = $record['ttl'] ?? $record['expiration'] - $currentTimestamp;
        $normalizedRecord = compact('value', 'expiration');

        return $store->put($key, $normalizedRecord, $ttl);
    }

    protected function callStoresStacked(Closure $handler, Closure $bottomLayer): mixed
    {
        return array_reduce(array_reverse($this->stores), function ($stack, $store) use ($handler) {
            return function () use ($stack, $store, $handler) {
                return $handler($store, $stack);
            };
        }, $bottomLayer)();
    }

    protected function callStores(Closure $handler, ?Closure $rollback = null, bool $force = false): bool
    {
        return $this->callStoresStacked(
            function (Store $store, Closure $next) use ($handler, $rollback, $force): bool {
                if (! $handler($store) && ! $force) {
                    return false;
                }

                $result = $next();

                if (! $result && $rollback) {
                    $rollback($store);
                }

                return $result;
            },
            static fn () => true
        );
    }
}
