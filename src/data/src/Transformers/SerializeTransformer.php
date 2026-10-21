<?php

declare(strict_types=1);

namespace Hypervel\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;

class SerializeTransformer implements Transformer
{
    /**
     * Transform a value into its PHP serialized string.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): string
    {
        return serialize($value);
    }
}
