<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class UlarData extends Data
{
    /**
     * Create the circular data fixture.
     */
    public function __construct(
        public string $string,
        public ?CircData $circ,
    ) {
    }
}
