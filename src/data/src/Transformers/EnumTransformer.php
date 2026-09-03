<?php

declare(strict_types=1);

namespace Hypervel\Data\Transformers;

use BackedEnum;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;

class EnumTransformer implements Transformer
{
    /**
     * Transform a backed enum value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): string|int
    {
        /** @var BackedEnum $value */
        return $value->value;
    }
}
