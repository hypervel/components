<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Tests\Data\Fixtures\Enums\PropertyMorphableEnum;

class PropertyMorphableDataB extends AbstractPropertyMorphableData
{
    /**
     * Create the second property-morphable variant.
     */
    public function __construct(
        public string $b,
    ) {
        parent::__construct(PropertyMorphableEnum::B);
    }
}
