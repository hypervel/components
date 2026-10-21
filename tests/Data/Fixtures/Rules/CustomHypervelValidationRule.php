<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Rules;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;

class CustomHypervelValidationRule implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }
}
