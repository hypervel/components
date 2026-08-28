<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Stringable;

readonly class BinaryParameter implements Stringable
{
    /**
     * Create a new binary parameter instance.
     */
    public function __construct(public string $value)
    {
    }

    /**
     * Return the binary parameter value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
