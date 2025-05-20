<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

use Closure;

interface InvokableRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): \Hypervel\Translation\PotentiallyTranslatedString $fail
     */
    public function __invoke(string $attribute, mixed $value, Closure $fail): void;
}
