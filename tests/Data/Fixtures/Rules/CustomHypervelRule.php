<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Rules;

use Hypervel\Contracts\Validation\Rule as RuleContract;

class CustomHypervelRule implements RuleContract
{
    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        return true;
    }

    /**
     * Get the validation error message.
     */
    public function message(): array|string
    {
        return '';
    }
}
