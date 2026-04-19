<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use InvalidArgumentException;
use Stringable;

class RequiredIf implements Stringable
{
    /**
     * The condition that validates the attribute.
     *
     * @var bool|callable
     */
    public $condition;

    /**
     * Create a new required validation rule based on a condition.
     */
    public function __construct(bool|callable $condition)
    {
        if (! is_string($condition)) {
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
            return call_user_func($this->condition) ? 'required' : '';
        }

        return $this->condition ? 'required' : '';
    }
}
