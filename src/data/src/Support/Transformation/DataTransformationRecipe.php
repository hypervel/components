<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Transformation;

use Hypervel\Data\Support\DataProperty;

/**
 * Immutable property order for lean transformation.
 */
final readonly class DataTransformationRecipe
{
    /**
     * Create a new data transformation recipe.
     *
     * @param list<DataProperty> $properties
     */
    public function __construct(
        public array $properties,
    ) {
    }
}
