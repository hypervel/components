<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Translation\PotentiallyTranslatedString;

class RejectEmptyString implements ValidationRule
{
    /**
     * Indicates whether the rule should be implicit.
     *
     * Hypervel skips non-implicit rules for an empty or whitespace-only string, so only an implicit rule can refuse one.
     */
    public bool $implicit = true;

    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) === '') {
            $fail('validation.filled')->translate();
        }
    }
}
