<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataProperty;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class AutoLazy
{
    /**
     * Build an automatic lazy value.
     */
    public function build(Closure $castValue, mixed $payload, DataProperty $property, mixed $value): Lazy
    {
        return Lazy::create(fn () => $castValue($value));
    }
}
