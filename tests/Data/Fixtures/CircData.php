<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class CircData extends Data
{
    /**
     * Create the circular data fixture.
     */
    public function __construct(
        public string $string,
        public ?UlarData $ular,
    ) {
    }
}
