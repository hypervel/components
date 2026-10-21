<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class SimpleDataWithDefaultValue extends Data
{
    /**
     * Create a data object with a defaulted string.
     */
    public function __construct(
        public string $string = 'default'
    ) {
    }

    /**
     * Create the data object from a string.
     */
    public static function fromString(string $string): static
    {
        return new static($string);
    }
}
