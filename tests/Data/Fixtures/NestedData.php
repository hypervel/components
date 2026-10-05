<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class NestedData extends Data
{
    /**
     * Create a fixture with a nested data object.
     */
    public function __construct(
        public SimpleData $simple
    ) {
    }
}
