<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Transformers;

use Hypervel\Data\Data;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\Transformer;

use function collect;

class ConfidentialDataTransformer implements Transformer
{
    /**
     * Replace every value of the data object with a placeholder.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): array
    {
        /** @var Data $value */
        return collect($value->toArray())->map(fn (mixed $value): string => 'CONFIDENTIAL')->toArray();
    }
}
