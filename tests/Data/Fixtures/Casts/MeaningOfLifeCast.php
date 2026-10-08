<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Casts;

use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\IterableItemCast;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

class MeaningOfLifeCast implements Cast, IterableItemCast
{
    /**
     * Replace the value with the meaning of life.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): int
    {
        return 42;
    }

    /**
     * Replace an iterable item with the meaning of life.
     */
    public function castIterableItem(DataProperty $property, mixed $value, array $properties, CreationContext $context): int
    {
        return 42;
    }
}
