<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Lazy\ClosureLazy;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class AutoClosureLazy extends AutoLazy
{
    /**
     * Build an automatic closure lazy value.
     */
    public function build(Closure $castValue, mixed $payload, DataProperty $property, mixed $value): ClosureLazy
    {
        return Lazy::closure(fn () => $castValue($value));
    }
}
