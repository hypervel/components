<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Transformers;

use Hypervel\Data\Data;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\Transformer;

class ConfidentialDataCollectionTransformer implements Transformer
{
    /**
     * Replace every value of each data item with a placeholder.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): array
    {
        /** @var array $value */
        return array_map(fn (Data $data): array => (new ConfidentialDataTransformer)->transform($property, $data, $context), $value);
    }
}
