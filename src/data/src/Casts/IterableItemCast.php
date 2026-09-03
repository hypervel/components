<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

interface IterableItemCast
{
    /**
     * Cast one item within an iterable property.
     */
    public function castIterableItem(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): mixed;
}
