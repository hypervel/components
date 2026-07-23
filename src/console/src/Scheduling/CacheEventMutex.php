<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;

class CacheEventMutex implements EventMutex, CacheAware
{
    /**
     * The cache store that should be used.
     */
    public ?string $store = null;

    /**
     * Create a new overlapping strategy.
     *
     * @param CacheFactory $cache the cache repository implementation
     */
    public function __construct(
        public CacheFactory $cache
    ) {
    }

    /**
     * Attempt to obtain an event mutex for the given event.
     */
    public function create(Event $event): bool
    {
        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            return $store
                ->lock($event->mutexName(), $event->expiresAt * 60)
                ->acquire();
        }

        return $repository->add(
            $event->mutexName(),
            true,
            $event->expiresAt * 60
        );
    }

    /**
     * Determine if an event mutex exists for the given event.
     */
    public function exists(Event $event): bool
    {
        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            return ! $store
                ->lock($event->mutexName(), $event->expiresAt * 60)
                ->get(fn () => true);
        }

        return $repository->has($event->mutexName());
    }

    /**
     * Clear the event mutex for the given event.
     */
    public function forget(Event $event): void
    {
        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            $store
                ->lock($event->mutexName(), $event->expiresAt * 60)
                ->forceRelease();

            return;
        }

        $repository->forget($event->mutexName());
    }

    /**
     * Determine if the given store should use locks for cache event mutexes.
     *
     * @phpstan-assert-if-true LockProvider $store
     */
    protected function shouldUseLocks(Store $store): bool
    {
        return $store instanceof LockProvider;
    }

    /**
     * Specify the cache store that should be used.
     *
     * Boot-only. Mutates the shared mutex instance for the worker lifetime;
     * per-request use races across coroutines.
     */
    public function useStore(?string $store): static
    {
        $this->store = $store;

        return $this;
    }
}
