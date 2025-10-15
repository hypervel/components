<?php

namespace Hypervel\View;

use Stringable;

class AppendableAttributeValue implements Stringable
{
    /**
     * The attribute value.
     *
     * @var mixed
     */
    public mixed $value;

    /**
     * Create a new appendable attribute value.
     *
     * @param  mixed  $value
     * @return void
     */
    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * Get the string value.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
