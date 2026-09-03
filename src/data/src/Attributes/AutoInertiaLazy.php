<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Lazy\InertiaLazy;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class AutoInertiaLazy extends AutoLazy
{
    /**
     * Build an automatic Inertia lazy value.
     */
    public function build(Closure $castValue, mixed $payload, DataProperty $property, mixed $value): InertiaLazy
    {
        return Lazy::inertia(fn () => $castValue($value));
    }
}
