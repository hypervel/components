<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Closure;
use Stringable;

class RequiredIf implements Stringable
{
    /**
     * The condition that validates the attribute.
     */
    public bool|Closure $condition;

    /**
     * Create a new required validation rule based on a condition.
     */
    public function __construct(bool|Closure|null $condition)
    {
        $this->condition = $condition ?? false;
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        if (is_callable($this->condition)) {
            return call_user_func($this->condition) ? 'required' : '';
        }

        return $this->condition ? 'required' : '';
    }
}
