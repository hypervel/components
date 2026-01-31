<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

use Closure;

interface ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): \Hypervel\Translation\PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void;
}
