<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Conditionable;
use Stringable;

class Numeric implements Stringable
{
    use Conditionable;

    /**
     * The constraints for the number rule.
     */
    protected array $constraints = ['numeric'];

    /**
     * The field under validation must have a size between the given min and max (inclusive).
     */
    public function between(float|int $min, float|int $max): static
    {
        return $this->addRule('between:' . $min . ',' . $max);
    }

    /**
     * The field under validation must contain the specified number of decimal places.
     */
    public function decimal(int $min, ?int $max = null): static
    {
        $rule = 'decimal:' . $min;

        if ($max !== null) {
            $rule .= ',' . $max;
        }

        return $this->addRule($rule);
    }

    /**
     * The field under validation must have a different value than field.
     */
    public function different(string $field): static
    {
        return $this->addRule('different:' . $field);
    }

    /**
     * The integer under validation must have an exact number of digits.
     */
    public function digits(int $length): static
    {
        return $this->integer()->addRule('digits:' . $length);
    }

    /**
     * The integer under validation must between the given min and max number of digits.
     */
    public function digitsBetween(int $min, int $max): static
    {
        return $this->integer()->addRule('digits_between:' . $min . ',' . $max);
    }

    /**
     * The field under validation must be greater than the given field or value.
     */
    public function greaterThan(string $field): static
    {
        return $this->addRule('gt:' . $field);
    }

    /**
     * The field under validation must be greater than or equal to the given field or value.
     */
    public function greaterThanOrEqualTo(string $field): static
    {
        return $this->addRule('gte:' . $field);
    }

    /**
     * The field under validation must be an integer.
     */
    public function integer(): static
    {
        return $this->addRule('integer');
    }

    /**
     * The field under validation must be less than the given field.
     */
    public function lessThan(string $field): static
    {
        return $this->addRule('lt:' . $field);
    }

    /**
     * The field under validation must be less than or equal to the given field.
     */
    public function lessThanOrEqualTo(string $field): static
    {
        return $this->addRule('lte:' . $field);
    }

    /**
     * The field under validation must be less than or equal to a maximum value.
     */
    public function max(float|int $value): static
    {
        return $this->addRule('max:' . $value);
    }

    /**
     * The integer under validation must have a maximum number of digits.
     */
    public function maxDigits(int $value): static
    {
        return $this->addRule('max_digits:' . $value);
    }

    /**
     * The field under validation must have a minimum value.
     */
    public function min(float|int $value): static
    {
        return $this->addRule('min:' . $value);
    }

    /**
     * The integer under validation must have a minimum number of digits.
     */
    public function minDigits(int $value): static
    {
        return $this->addRule('min_digits:' . $value);
    }

    /**
     * The field under validation must be a multiple of the given value.
     */
    public function multipleOf(float|int $value): static
    {
        return $this->addRule('multiple_of:' . $value);
    }

    /**
     * The given field must match the field under validation.
     */
    public function same(string $field): static
    {
        return $this->addRule('same:' . $field);
    }

    /**
     * The field under validation must match the given value.
     */
    public function exactly(int $value): static
    {
        return $this->integer()->addRule('size:' . $value);
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        return implode('|', array_unique($this->constraints));
    }

    /**
     * Add custom rules to the validation rules array.
     */
    protected function addRule(array|string $rules): static
    {
        $this->constraints = array_merge($this->constraints, Arr::wrap($rules));

        return $this;
    }
}
