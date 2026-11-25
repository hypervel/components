<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Translation\PotentiallyTranslatedString;
use Hypervel\Validation\Membership;

class MatchesEnum implements ValidationRule
{
    /**
     * The lookup of the enumerated scalar values.
     *
     * @var array<int|string, true>
     */
    protected array $scalars;

    /**
     * Indicates if null is one of the enumerated values.
     */
    protected bool $allowsNull;

    /**
     * Create a new rule instance.
     *
     * @param array<int, mixed> $values
     */
    public function __construct(protected array $values)
    {
        [$this->scalars, $this->allowsNull] = Membership::lookup($values);
    }

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->matches($value)) {
            $fail('validation.in')->translate();
        }
    }

    /**
     * Determine if the value is one of the enumerated values.
     *
     * Scalars are compared like the "in" rule, and arrays strictly by content, since loose comparison treats "1" and "01" (or true and any array) as equal.
     */
    protected function matches(mixed $value): bool
    {
        if (! is_array($value)) {
            return Membership::contains($this->scalars, $this->allowsNull, $value);
        }

        $value = DistinctArrayItems::normalize($value);

        foreach ($this->values as $allowed) {
            if (is_array($allowed) && DistinctArrayItems::normalize($allowed) === $value) {
                return true;
            }
        }

        return false;
    }
}
