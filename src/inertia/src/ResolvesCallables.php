<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Support\Facades\App;

trait ResolvesCallables
{
    /**
     * Call the given value if callable and inject its dependencies.
     */
    protected function resolveCallable(mixed $value): mixed
    {
        return $this->useAsCallable($value) ? App::call($value) : $value;
    }

    /**
     * Determine if the given value is callable, but not a string.
     */
    protected function useAsCallable(mixed $value): bool
    {
        return is_object($value) && is_callable($value);
    }
}
