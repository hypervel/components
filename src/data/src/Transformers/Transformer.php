<?php

declare(strict_types=1);

namespace Hypervel\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;

interface Transformer
{
    /**
     * Transform a property value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed;
}
