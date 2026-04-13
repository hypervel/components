<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Routing;

use Closure;

interface BindingRegistrar
{
    /**
     * Add a new route parameter binder.
     */
    public function bind(string $key, string|callable $binder): void;

    /**
     * Get the binding callback for a given binding.
     */
    public function getBindingCallback(string $key): ?Closure;
}
