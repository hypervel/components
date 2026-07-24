<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use DateTimeInterface;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;

class CacheSchedulingMutex implements SchedulingMutex, CacheAware
{
    /**
     * The cache store that should be used.
     */
    public ?string $store = null;

    /**
     * Create a new scheduling strategy.
     *
     * @param CacheFactory $cache the cache factory implementation
     */
    public function __construct(
        public CacheFactory $cache
    ) {
    }

    /**
     * Attempt to obtain a scheduling mutex for the given event.
     */
    public function create(Event $event, DateTimeInterface $time): bool
    {
        $mutexName = $event->mutexName() . $time->format('Hi');

        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            return $store
                ->lock($mutexName, 3600)
                ->acquire();
        }

        return $repository->add(
            $mutexName,
            true,
            3600
        );
    }

    /**
     * Determine if a scheduling mutex exists for the given event.
     */
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        $mutexName = $event->mutexName() . $time->format('Hi');

        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            return ! $store
                ->lock($mutexName, 3600)
                ->get(fn () => true);
        }

        return $repository->has($mutexName);
    }

    /**
     * Determine if the given store should use locks for cache scheduling mutexes.
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
