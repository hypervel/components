<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Data\Support\DataProperty;

/**
 * Immutable property order for lean construction.
 */
final readonly class DataCreationRecipe
{
    /**
     * Create a new data creation recipe.
     *
     * @param list<DataProperty> $properties
     */
    public function __construct(
        public array $properties,
    ) {
    }
}
