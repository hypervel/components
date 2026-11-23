<?php

declare(strict_types=1);

namespace Hypervel\Console\Scheduling;

use Hypervel\Support\Attributes\BootOnly;

interface CacheAware
{
    /**
     * Specify the cache store that should be used.
     *
     * Mutates the shared mutex instance for the worker lifetime;
     * per-request use races across coroutines.
     *
     * @return $this
     */
    #[BootOnly(whenShared: true)]
    public function useStore(string $store): static;
}
