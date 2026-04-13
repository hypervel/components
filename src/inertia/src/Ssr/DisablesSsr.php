<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

use Closure;

interface DisablesSsr
{
    /**
     * Set the condition that determines if SSR should be disabled.
     */
    public function disable(Closure|bool $condition): void;
}
