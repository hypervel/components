<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Translation\PotentiallyTranslatedString;
use Hypervel\Validation\Membership;
use stdClass;

use function Hypervel\Support\enum_value;

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
        [$this->scalars, $this->allowsNull] = Membership::lookup(array_map(enum_value(...), $values));
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
     * Scalars are compared like the "in" rule, and arrays and objects strictly by content, since loose comparison treats "1" and "01" (or true and any array) as equal.
     */
    protected function matches(mixed $value): bool
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            return Membership::contains($this->scalars, $this->allowsNull, $value);
        }

        $value = DistinctArrayItems::normalize($value);

        foreach ($this->values as $allowed) {
            if ((is_array($allowed) || $allowed instanceof stdClass) && DistinctArrayItems::normalize($allowed) === $value) {
                return true;
            }
        }

        return false;
    }
}
