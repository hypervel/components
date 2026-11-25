<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;
use Hypervel\Translation\PotentiallyTranslatedString;

class MatchesAnyOf implements ValidationRule
{
    /**
     * Create a new rule instance.
     *
     * @param array<int, array<string, array<int, mixed>>> $branches the rules for each branch, keyed under "value"
     */
    public function __construct(protected array $branches)
    {
    }

    /**
     * Determine if any branch allows a null value.
     */
    public function allowsNull(): bool
    {
        return Arr::some($this->branches, fn (array $rules) => in_array('nullable', $rules['value'], true));
    }

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->branches as $rules) {
            if (Validator::make(['value' => $value], $rules)->passes()) {
                return;
            }
        }

        $fail('validation.any_of')->translate();
    }
}
