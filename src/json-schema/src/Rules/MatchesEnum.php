<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Translation\PotentiallyTranslatedString;
use stdClass;

use function Hypervel\Support\enum_value;

class MatchesEnum implements ValidationRule
{
    /**
     * The lookup of the enumerated scalar values.
     *
     * @var array<string, true>
     */
    protected array $scalars = [];

    /**
     * Create a new rule instance.
     *
     * @param array<int, mixed> $values
     */
    public function __construct(protected array $values)
    {
        foreach ($values as $allowed) {
            $allowed = enum_value($allowed);

            if (($allowed === null || is_scalar($allowed)) && ! (is_float($allowed) && is_nan($allowed))) {
                $this->scalars[$this->scalarKey($allowed)] = true;
            }
        }
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
     * Compare scalars strictly and arrays and objects by content. Loose comparison
     * treats "1" and "01" as equal and matches true with values such as "abc" or [1].
     */
    protected function matches(mixed $value): bool
    {
        if (! is_array($value) && ! $value instanceof stdClass) {
            return ($value === null || is_scalar($value)) && isset($this->scalars[$this->scalarKey($value)]);
        }

        $value = DistinctArrayItems::normalize($value);

        foreach ($this->values as $allowed) {
            if ((is_array($allowed) || $allowed instanceof stdClass) && DistinctArrayItems::normalize($allowed) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index scalars by type and value without depending on PHP's float serialization precision.
     */
    private function scalarKey(bool|float|int|string|null $value): string
    {
        return is_float($value) ? 'f' . pack('e', $value === 0.0 ? 0.0 : $value) : serialize($value);
    }
}
