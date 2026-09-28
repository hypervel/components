<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Stringable;

class EncodedParameter implements Stringable
{
    /**
     * Create a new encoded parameter instance.
     */
    public function __construct(protected string $value)
    {
    }

    /**
     * Get the encoded parameter value.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Get the encoded parameter value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
