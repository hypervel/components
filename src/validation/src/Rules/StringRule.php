<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Conditionable;
use Stringable;

class StringRule implements Stringable
{
    use Conditionable;

    /**
     * The constraints for the string rule.
     */
    protected array $constraints = ['string'];

    /**
     * The field under validation must be entirely alphabetic characters.
     *
     * @return $this
     */
    public function alpha(bool $ascii = false): static
    {
        return $this->addRule($ascii ? 'alpha:ascii' : 'alpha');
    }

    /**
     * The field under validation must be entirely alpha-numeric characters, dashes, and underscores.
     *
     * @return $this
     */
    public function alphaDash(bool $ascii = false): static
    {
        return $this->addRule($ascii ? 'alpha_dash:ascii' : 'alpha_dash');
    }

    /**
     * The field under validation must be entirely alpha-numeric characters.
     *
     * @return $this
     */
    public function alphaNumeric(bool $ascii = false): static
    {
        return $this->addRule($ascii ? 'alpha_num:ascii' : 'alpha_num');
    }

    /**
     * The field under validation must be entirely ASCII characters.
     *
     * @return $this
     */
    public function ascii(): static
    {
        return $this->addRule('ascii');
    }

    /**
     * The field under validation must have a length between the given min and max (inclusive).
     *
     * @return $this
     */
    public function between(int $min, int $max): static
    {
        return $this->addRule('between:' . $min . ',' . $max);
    }

    /**
     * The field under validation must not end with any of the given values.
     *
     * @return $this
     */
    public function doesntEndWith(string ...$values): static
    {
        return $this->addRule('doesnt_end_with:' . $this->formatValues($values));
    }

    /**
     * The field under validation must not start with any of the given values.
     *
     * @return $this
     */
    public function doesntStartWith(string ...$values): static
    {
        return $this->addRule('doesnt_start_with:' . $this->formatValues($values));
    }

    /**
     * The field under validation must end with one of the given values.
     *
     * @return $this
     */
    public function endsWith(string ...$values): static
    {
        return $this->addRule('ends_with:' . $this->formatValues($values));
    }

    /**
     * The field under validation must have an exact length.
     *
     * @return $this
     */
    public function exactly(int $value): static
    {
        return $this->addRule('size:' . $value);
    }

    /**
     * The field under validation must be entirely lowercase.
     *
     * @return $this
     */
    public function lowercase(): static
    {
        return $this->addRule('lowercase');
    }

    /**
     * The field under validation must not exceed the given length.
     *
     * @return $this
     */
    public function max(int $value): static
    {
        return $this->addRule('max:' . $value);
    }

    /**
     * The field under validation must have a minimum length.
     *
     * @return $this
     */
    public function min(int $value): static
    {
        return $this->addRule('min:' . $value);
    }

    /**
     * The field under validation must start with one of the given values.
     *
     * @return $this
     */
    public function startsWith(string ...$values): static
    {
        return $this->addRule('starts_with:' . $this->formatValues($values));
    }

    /**
     * The field under validation must be entirely uppercase.
     *
     * @return $this
     */
    public function uppercase(): static
    {
        return $this->addRule('uppercase');
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        return implode('|', $this->toArray());
    }

    /**
     * Convert the rule to an array of validation rules.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_values(array_unique($this->constraints));
    }

    /**
     * Format literal values as CSV parameters.
     *
     * @param list<string> $values
     */
    protected function formatValues(array $values): string
    {
        return implode(',', array_map(
            static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
            $values,
        ));
    }

    /**
     * Add custom rules to the validation rules array.
     *
     * @return $this
     */
    protected function addRule(array|string $rules): static
    {
        $this->constraints = array_merge($this->constraints, Arr::wrap($rules));

        return $this;
    }
}
