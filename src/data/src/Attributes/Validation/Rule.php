<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Contracts\Validation\InvokableRule as InvokableRuleContract;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\ValidationRule as ValidationRuleContract;
use Hypervel\Data\Support\Validation\ValidationRule;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Rule extends ValidationRule
{
    /** @var array<array|InvokableRuleContract|RuleContract|string|ValidationRule|ValidationRuleContract> */
    protected array $rules = [];

    /**
     * Create a custom rule attribute.
     */
    public function __construct(string|array|ValidationRule|RuleContract|InvokableRuleContract|ValidationRuleContract ...$rules)
    {
        $this->rules = $rules;
    }

    /**
     * Get the wrapped Validator rules.
     */
    public function get(): array
    {
        return $this->rules;
    }
}
