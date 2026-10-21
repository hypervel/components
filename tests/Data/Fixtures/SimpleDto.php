<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Dto;

class SimpleDto extends Dto
{
    /**
     * Create a simple transfer object.
     */
    public function __construct(
        public string $string
    ) {
    }

    /**
     * Create the transfer object from a string.
     */
    public static function fromString(string $string): static
    {
        return new static($string);
    }
}
