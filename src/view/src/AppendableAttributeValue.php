<?php

declare(strict_types=1);

namespace Hypervel\View;

use Stringable;

class AppendableAttributeValue implements Stringable
{
    /**
     * Create a new appendable attribute value.
     */
    public function __construct(
        public mixed $value
    ) {
    }

    /**
     * Get the string value.
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
