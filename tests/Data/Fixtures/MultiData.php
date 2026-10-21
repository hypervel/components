<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class MultiData extends Data
{
    /**
     * Create a fixture with two required properties.
     */
    public function __construct(
        public string $first,
        public string $second,
    ) {
    }
}
