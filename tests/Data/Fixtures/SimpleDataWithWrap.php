<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class SimpleDataWithWrap extends Data
{
    /**
     * Create a fixture with a default wrap key.
     */
    public function __construct(
        public string $string
    ) {
    }

    /**
     * Create the fixture from a string.
     */
    public static function fromString(string $string): static
    {
        return new static($string);
    }

    /**
     * Get the key that wraps the data object's responses.
     */
    public function defaultWrap(): string
    {
        return 'wrap';
    }
}
