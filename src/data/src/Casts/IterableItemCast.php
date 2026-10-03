<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

interface IterableItemCast
{
    /**
     * Cast one item within an iterable property.
     *
     * @param array<string, mixed> $properties the object's declared property values, keyed by property name
     */
    public function castIterableItem(
        DataProperty $property,
        mixed $value,
        array $properties,
        CreationContext $context,
    ): mixed;
}
