<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Support\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class StackStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    /**
     * @var StackStoreProxy[]
     */
    protected array $stores;

    /**
     * Memoized tag-composition validation error.
     *
     * false means validation has not run, null means the composition is valid,
     * and a string contains the validation error for an invalid composition.
     */
    protected false|string|null $tagCompositionError = false;

    /**
     * @param array<int, StackStoreProxy|Store> $stores
     *
     * @throws InvalidArgumentException when no layers are given
     */
    public function __construct(array $stores)
    {
        if ($stores === []) {
            throw new InvalidArgumentException('A cache stack requires at least one store layer.');
        }

        $this->stores = array_map(
            static fn (Store $store) => $store instanceof StackStoreProxy ? $store : new StackStoreProxy($store),
            $stores
        );
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
        $result = true;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $seconds) && $result;
        }

        return $result;
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
            fn (StackStoreProxy $store) => $store->forever($key, $record),
            fn (StackStoreProxy $store) => $store->forget($key),
        );
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        $record = $this->getOrRestoreRecord($key);

        if (is_null($record)) {
            return false;
        }

        $record['ttl'] = $seconds;
        unset($record['expiration']);

        return $this->putRecord($key, $record);
    }

    public function forget(string $key): bool
    {
        return $this->callAllStores(fn (StackStoreProxy $store) => $store->forget($key));
    }

    public function flush(): bool
    {
        return $this->callAllStores(static fn (StackStoreProxy $store) => $store->flush());
    }

    /**
     * Get a lock instance.
     *
     * Locks are delegated to the bottom layer and never touch the cache tiers.
     *
     * @throws NotSupportedException when the bottom layer is not a lock provider
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return $this->bottomLockProvider()->lock($name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @throws NotSupportedException when the bottom layer is not a lock provider
     */
    public function restoreLock(string $name, string $owner): Lock
    {
        return $this->bottomLockProvider()->restoreLock($name, $owner);
    }

    /**
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
        $store = $this->bottomStore();

        return $store instanceof CanFlushLocks && $store->supportsFlushingLocks();
    }

    /**
     * Flush all locks managed by the store.
     *
     * @throws NotSupportedException when the bottom layer cannot flush locks
     */
    public function flushLocks(): bool
    {
        $store = $this->bottomStore();

        if (! $store instanceof CanFlushLocks || ! $store->supportsFlushingLocks()) {
            throw new NotSupportedException(sprintf(
                'The stack\'s bottom layer [%s] does not support flushing locks.',
                $store::class
            ));
        }

        return $store->flushLocks();
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        $store = $this->bottomStore();

        return $store instanceof CanFlushLocks && $store->hasSeparateLockStore();
    }

    /**
     * Begin executing a new tags operation.
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    public function tags(mixed $names): TaggedCache
    {
        $this->ensureTagCompositionIsValid();

        return new StackTaggedCache($this, new StackTagSet($this, is_array($names) ? $names : func_get_args()));
    }

    /**
     * Determine if this store currently supports tags.
     */
    public function supportsTags(): bool
    {
        return is_null($this->tagCompositionError());
    }

    /**
     * Get the tag mode this store operates under.
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    public function getTagMode(): TagMode
    {
        $this->ensureTagCompositionIsValid();

        return TagMode::Any;
    }

    public function getPrefix(): string
    {
        return '';
    }

    /**
     * Get the underlying taggable layer stores, top to bottom.
     *
     * @return array<int, TaggableStore>
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    public function taggableLayers(): array
    {
        $this->ensureTagCompositionIsValid();

        $layers = [];

        foreach ($this->stores as $proxy) {
            $store = $proxy->getStore();

            if ($store instanceof TaggableStore) {
                $layers[] = $store;
            }
        }

        return $layers;
    }

    protected function getOrRestoreRecord(string $key): ?array
    {
        return $this->callStoresStacked(
            function (StackStoreProxy $store, Closure $next) use ($key): ?array {
                if (! is_null($record = $store->get($key))) {
                    return (array) $record;
                }

                if (is_null($record = $next()) || ! array_key_exists('value', $record)) {
                    return null;
                }

                $this->putToStore($store, $key, $record);

                return $record;
            },
            static fn (): null => null
        );
    }

    protected function putRecord(string $key, array $record): bool
    {
        return $this->callStores(
            fn (StackStoreProxy $store) => $this->putToStore($store, $key, $record),
            fn (StackStoreProxy $store) => $store->forget($key),
        );
    }

    /**
     * Store a record in all layers, indexing it under the given tags in the taggable layers.
     *
     * @param array<string> $tags
     */
    public function putRecordTagged(array $tags, string $key, array $record): bool
    {
        return $this->callStores(
            fn (StackStoreProxy $store) => $this->putToStoreTagged($store, $tags, $key, $record),
            fn (StackStoreProxy $store) => $store->forget($key),
        );
    }

    protected function putToStore(StackStoreProxy $store, string $key, array $record): bool
    {
        if (! array_key_exists('value', $record)) {
            return false;
        }

        if (! array_key_exists('expiration', $record) && ! array_key_exists('ttl', $record)) {
            return $store->forever($key, $record);
        }

        $currentTimestamp = CarbonImmutable::now()->getTimestamp();
        $value = $record['value'];
        $expiration = $record['expiration'] ?? $currentTimestamp + $record['ttl'];
        $ttl = $record['ttl'] ?? $record['expiration'] - $currentTimestamp;
        $normalizedRecord = compact('value', 'expiration');

        return $store->put($key, $normalizedRecord, $ttl);
    }

    /**
     * Store a record in a single layer via the layer's tagged write path when possible.
     *
     * Tagged writes bypass StackStoreProxy, so this mirrors putToStore()'s TTL clamp.
     *
     * @param array<string> $tags
     */
    protected function putToStoreTagged(StackStoreProxy $proxy, array $tags, string $key, array $record): bool
    {
        $store = $proxy->getStore();

        if (! $store instanceof TaggableStore) {
            return $this->putToStore($proxy, $key, $record);
        }

        if (! array_key_exists('value', $record)) {
            return false;
        }

        if (! array_key_exists('expiration', $record) && ! array_key_exists('ttl', $record)) {
            if (is_null($proxyTtl = $proxy->getTtl())) {
                return $store->tags($tags)->forever($key, $record);
            }

            return $store->tags($tags)->put($key, $record, $proxyTtl);
        }

        $currentTimestamp = CarbonImmutable::now()->getTimestamp();
        $value = $record['value'];
        $expiration = $record['expiration'] ?? $currentTimestamp + $record['ttl'];
        $ttl = $record['ttl'] ?? $record['expiration'] - $currentTimestamp;
        $normalizedRecord = compact('value', 'expiration');

        $proxyTtl = $proxy->getTtl();
        $effectiveTtl = is_null($proxyTtl) || $ttl < $proxyTtl ? $ttl : $proxyTtl;

        return $store->tags($tags)->put($key, $normalizedRecord, $effectiveTtl);
    }

    /**
     * Increment a record's value, indexing the write under the given tags.
     *
     * @param array<string> $tags
     */
    public function incrementTagged(array $tags, string $key, int $value = 1): bool|int
    {
        $record = $this->getOrRestoreRecord($key);

        if (is_null($record)) {
            return tap($value, fn ($value) => $this->putRecordTagged($tags, $key, ['value' => $value]));
        }

        $newValue = $record['value'] + $value;
        $newRecord = ['value' => $newValue] + $record;

        if ($this->putRecordTagged($tags, $key, $newRecord)) {
            return $newValue;
        }

        return false;
    }

    /**
     * Ensure the layer composition can support stack tags.
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    protected function ensureTagCompositionIsValid(): void
    {
        if (! is_null($error = $this->tagCompositionError())) {
            throw new NotSupportedException($error);
        }
    }

    protected function tagCompositionError(): ?string
    {
        if ($this->tagCompositionError === false) {
            $this->tagCompositionError = $this->validateTagComposition();
        }

        return $this->tagCompositionError;
    }

    /**
     * Validate the layer composition for tag support.
     *
     * A non-taggable or all-mode layer below the taggable region would
     * resurrect flushed values on read-through, silently undoing invalidation.
     */
    protected function validateTagComposition(): ?string
    {
        $firstTaggable = null;

        foreach ($this->stores as $index => $proxy) {
            $store = $proxy->getStore();

            if ($firstTaggable === null) {
                if ($store instanceof TaggableStore) {
                    $firstTaggable = $index;
                } else {
                    continue;
                }
            }

            if (! $store instanceof TaggableStore) {
                return sprintf(
                    'Stack layer %d [%s] does not support tags. Layers below the first taggable layer must all be any-mode taggable stores.',
                    $index,
                    $store::class
                );
            }

            if (! $store->supportsTags() || $store->getTagMode() !== TagMode::Any) {
                return sprintf(
                    'Stack layer %d [%s] must be a taggable store in any mode to participate in stack tags.',
                    $index,
                    $store::class
                );
            }
        }

        if ($firstTaggable === null) {
            return 'The stack has no taggable layer; stack tags require at least one any-mode taggable store.';
        }

        return null;
    }

    /**
     * Get the bottom layer's underlying store.
     */
    protected function bottomStore(): Store
    {
        return $this->stores[array_key_last($this->stores)]->getStore();
    }

    /**
     * Get the bottom layer's store as a lock provider.
     *
     * @throws NotSupportedException when the bottom layer is not a lock provider
     */
    protected function bottomLockProvider(): LockProvider
    {
        $store = $this->bottomStore();

        if (! $store instanceof LockProvider) {
            throw new NotSupportedException(sprintf(
                'The stack\'s bottom layer [%s] does not support locks. Use a lock-capable store as the bottom layer.',
                $store::class
            ));
        }

        return $store;
    }

    protected function callStoresStacked(Closure $handler, Closure $bottomLayer): mixed
    {
        return array_reduce(array_reverse($this->stores), function ($stack, $store) use ($handler) {
            return function () use ($stack, $store, $handler) {
                return $handler($store, $stack);
            };
        }, $bottomLayer)();
    }

    protected function callStores(Closure $handler, ?Closure $rollback = null): bool
    {
        $completed = [];
        $result = true;
        $exception = null;

        foreach ($this->stores as $store) {
            try {
                if (! $handler($store)) {
                    $result = false;

                    break;
                }

                $completed[] = $store;
            } catch (Throwable $throwable) {
                $exception = $throwable;

                break;
            }
        }

        if ($result && $exception === null) {
            return true;
        }

        if ($rollback !== null) {
            foreach (array_reverse($completed) as $store) {
                try {
                    $rollback($store);
                } catch (Throwable) {
                    // Preserve the write failure that made compensation necessary.
                }
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return false;
    }

    /**
     * Call the handler for every store.
     */
    protected function callAllStores(Closure $handler): bool
    {
        $result = true;
        $exception = null;

        foreach ($this->stores as $store) {
            try {
                if (! $handler($store)) {
                    $result = false;
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }
}
