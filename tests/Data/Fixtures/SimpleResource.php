<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Resource;

class SimpleResource extends Resource
{
    /**
     * Create a simple resource.
     */
    public function __construct(
        public string $string
    ) {
    }

    /**
     * Create the resource from a string.
     */
    public static function fromString(string $string): static
    {
        return new static($string);
    }
}
