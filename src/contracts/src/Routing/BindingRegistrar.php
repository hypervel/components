<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Routing;

use Closure;
use Hypervel\Support\Attributes\BootOnly;

interface BindingRegistrar
{
    /**
     * Add a new route parameter binder.
     */
    #[BootOnly(whenShared: true)]
    public function bind(string $key, string|callable $binder): void;

    /**
     * Get the binding callback for a given binding.
     */
    public function getBindingCallback(string $key): ?Closure;
}
