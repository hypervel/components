<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Casts;

use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class ConfidentialDataCast implements Cast
{
    /**
     * Replace the value with confidential data.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): SimpleData
    {
        return SimpleData::from('CONFIDENTIAL');
    }
}
