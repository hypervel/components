<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class NestedNullableData extends Data
{
    /**
     * Create the nested nullable data fixture.
     */
    public function __construct(
        public ?SimpleData $nested
    ) {
    }
}
