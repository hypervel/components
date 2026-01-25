<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use DateTimeInterface;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Console\Contracts\CacheAware;
use Hypervel\Console\Contracts\SchedulingMutex;

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
        return $this->cache->store($this->store)->add(
            $event->mutexName() . $time->format('Hi'),
            true,
            3600
        );
    }

    /**
     * Determine if a scheduling mutex exists for the given event.
     */
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        return $this->cache->store($this->store)->has(
            $event->mutexName() . $time->format('Hi')
        );
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
