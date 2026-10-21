<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Tests\Data\Fixtures\Enums\PropertyMorphableEnum;

abstract class AbstractPropertyMorphableData extends Data implements PropertyMorphableData
{
    /**
     * Create a property-morphable fixture.
     */
    public function __construct(
        #[PropertyForMorph]
        public PropertyMorphableEnum $variant,
    ) {
    }

    /**
     * Resolve the concrete class for the variant.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['variant'] ?? null) {
            PropertyMorphableEnum::A => PropertyMorphableDataA::class,
            PropertyMorphableEnum::B => PropertyMorphableDataB::class,
            default => null,
        };
    }
}
