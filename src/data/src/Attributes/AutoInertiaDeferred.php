<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Lazy\InertiaDeferred;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class AutoInertiaDeferred extends AutoLazy
{
    /**
     * Create a new automatic Inertia deferred attribute.
     */
    public function __construct(
        protected readonly ?string $group = null,
        protected readonly bool $rescue = false,
    ) {
    }

    /**
     * Build an automatic Inertia deferred value.
     */
    public function build(Closure $castValue, mixed $payload, DataProperty $property, mixed $value): InertiaDeferred
    {
        return Lazy::inertiaDeferred(fn () => $castValue($value), $this->group, $this->rescue);
    }
}
