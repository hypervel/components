<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules\Concerns;

use Hypervel\Contracts\Validation\InvokableRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;

trait ClonesCustomRules
{
    /**
     * Clone the nested executable rule objects.
     */
    public function __clone(): void
    {
        foreach ($this->customRules as $key => $rule) {
            if ($rule instanceof Rule
                || $rule instanceof InvokableRule
                || $rule instanceof ValidationRule) {
                $this->customRules[$key] = clone $rule;
            }
        }
    }
}
