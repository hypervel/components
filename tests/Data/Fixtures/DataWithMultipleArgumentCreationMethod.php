<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class DataWithMultipleArgumentCreationMethod extends Data
{
    /**
     * Create the data object from its concatenated value.
     */
    public function __construct(
        public string $concatenated,
    ) {
    }

    /**
     * Create the data object from a string and a number.
     */
    public static function fromMultiple(string $string, int $number): self
    {
        return new self("{$string}_{$number}");
    }
}
