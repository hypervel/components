<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Data;

class MultiNestedData extends Data
{
    /**
     * Create a fixture with a nested data object and a nested data array.
     */
    public function __construct(
        public NestedData $nested,
        #[DataCollectionOf(NestedData::class)]
        public array $nestedCollection
    ) {
    }
}
