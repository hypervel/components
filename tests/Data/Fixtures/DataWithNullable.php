<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class DataWithNullable extends Data
{
    /**
     * Create a fixture with a required and a nullable string.
     */
    public function __construct(
        public string $string,
        public ?string $nullableString,
    ) {
    }
}
