<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\Enums\PropertyMorphableEnum;

class PropertyMorphableDataA extends AbstractPropertyMorphableData
{
    /**
     * Create the first property-morphable variant.
     */
    public function __construct(
        public string $a,
        public DummyBackedEnum $enum,
    ) {
        parent::__construct(PropertyMorphableEnum::A);
    }
}
