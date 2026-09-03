<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes;

use Attribute;
use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Lazy\ConditionalLazy;

#[Attribute(Attribute::TARGET_PROPERTY)]
class AutoWhenLoadedLazy extends AutoLazy
{
    /**
     * Create a new automatic relation lazy attribute.
     */
    public function __construct(
        protected readonly ?string $relation = null,
    ) {
    }

    /**
     * Build an automatic lazy value for a loaded relation.
     */
    public function build(Closure $castValue, mixed $payload, DataProperty $property, mixed $value): ConditionalLazy
    {
        $relation = $this->forRelation($property);

        return Lazy::when(fn () => $payload->relationLoaded($relation), fn () => $castValue(
            $payload->getRelation($relation)
        ));
    }

    /**
     * Get the relation represented by the property.
     */
    public function forRelation(DataProperty $property): string
    {
        return $this->relation ?? $property->name;
    }
}
