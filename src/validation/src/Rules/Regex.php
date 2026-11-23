<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Stringable;

class Regex implements Stringable
{
    /**
     * Create a new regex rule instance.
     */
    public function __construct(protected string $pattern)
    {
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        return "regex:{$this->pattern}";
    }
}
