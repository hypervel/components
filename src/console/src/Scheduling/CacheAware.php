<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

interface CacheAware
{
    /**
     * Specify the cache store that should be used.
     *
     * Boot-only. Mutates the shared mutex instance for the worker lifetime;
     * per-request use races across coroutines.
     */
    public function useStore(string $store): static;
}
