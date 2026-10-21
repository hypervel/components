<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Casts;

use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\IterableItemCast;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;

class StringToUpperCast implements Cast, IterableItemCast
{
    /**
     * Uppercase the value.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): string
    {
        return $this->castValue($value);
    }

    /**
     * Uppercase an iterable item.
     */
    public function castIterableItem(DataProperty $property, mixed $value, array $properties, CreationContext $context): string
    {
        return $this->castValue($value);
    }

    /**
     * Uppercase a string value.
     */
    private function castValue(mixed $value): string
    {
        return strtoupper($value);
    }
}
