<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\Transformer;

class StringToUpperTransformer implements Transformer
{
    /**
     * Uppercase the value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): string
    {
        return strtoupper($value);
    }
}
