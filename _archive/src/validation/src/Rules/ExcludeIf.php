<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Closure;
use Stringable;

class ExcludeIf implements Stringable
{
    /**
     * The condition that validates the attribute.
     */
    public bool|Closure $condition;

    /**
     * Create a new exclude validation rule based on a condition.
     */
    public function __construct(bool|Closure $condition)
    {
        $this->condition = $condition;
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        if (is_callable($this->condition)) {
            return call_user_func($this->condition) ? 'exclude' : '';
        }

        return $this->condition ? 'exclude' : '';
    }
}
