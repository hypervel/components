<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Console\CacheAware;
use Hypervel\Contracts\Console\EventMutex;

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
        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            /* @phpstan-ignore-next-line */
            return $this->cache->store($this->store)->getStore()
                ->lock($event->mutexName(), $event->expiresAt * 60)
                ->acquire();
        }

        return $this->cache->store($this->store)->add(
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
        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            /* @phpstan-ignore-next-line */
            return ! $this->cache->store($this->store)->getStore()
                ->lock($event->mutexName(), $event->expiresAt * 60)
                ->get(fn () => true);
        }

        return $this->cache->store($this->store)->has($event->mutexName());
    }

    /**
     * Clear the event mutex for the given event.
     */
    public function forget(Event $event): void
    {
        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            /* @phpstan-ignore-next-line */
            $this->cache->store($this->store)->getStore()
                ->lock($event->mutexName(), $event->expiresAt * 60)
                ->forceRelease();

            return;
        }

        $this->cache->store($this->store)->forget($event->mutexName());
    }

    /**
     * Determine if the given store should use locks for cache event mutexes.
     */
    protected function shouldUseLocks(Store $store): bool
    {
        return $store instanceof LockProvider;
    }

    /**
     * Specify the cache store that should be used.
     */
    public function useStore(?string $store): static
    {
        $this->store = $store;

        return $this;
    }
}
