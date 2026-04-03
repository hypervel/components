<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

use Closure;

/**
 * @deprecated see ValidationRule
 */
interface InvokableRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): \Hypervel\Translation\PotentiallyTranslatedString $fail
     */
    public function __invoke(string $attribute, mixed $value, Closure $fail): void;
}
