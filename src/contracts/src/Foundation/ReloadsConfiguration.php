<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Foundation;

interface ReloadsConfiguration
{
    /**
     * Reload configuration-derived worker state.
     *
     * Boot-only. Request-time use may replace shared worker state while
     * concurrent coroutines still hold the previous state.
     */
    public function reloadConfiguration(): void;
}
