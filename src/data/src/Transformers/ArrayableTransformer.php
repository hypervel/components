<?php

declare(strict_types=1);

namespace Hypervel\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;

class ArrayableTransformer implements Transformer
{
    /**
     * Transform an arrayable value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): array
    {
        return $value->toArray();
    }
}
