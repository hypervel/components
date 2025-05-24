<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Closure;
use InvalidArgumentException;
use Stringable;

class ProhibitedIf implements Stringable
{
    /**
     * The condition that validates the attribute.
     */
    public bool|Closure $condition;

    /**
     * Create a new prohibited validation rule based on a condition.
     *
     * @param bool|Closure $condition
     *
     * @throws InvalidArgumentException
     */
    public function __construct(mixed $condition)
    {
        if ($condition instanceof Closure || is_bool($condition)) {
            $this->condition = $condition;
        } else {
            throw new InvalidArgumentException('The provided condition must be a callable or boolean.');
        }
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        if (is_callable($this->condition)) {
            return call_user_func($this->condition) ? 'prohibited' : '';
        }

        return $this->condition ? 'prohibited' : '';
    }
}
