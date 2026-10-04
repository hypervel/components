<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class SimpleData extends Data
{
    /**
     * Create a simple data fixture.
     */
    public function __construct(
        public string $string
    ) {
    }

    /**
     * Create the fixture from a string.
     */
    public static function fromString(string $string): self
    {
        return new self($string);
    }
}
