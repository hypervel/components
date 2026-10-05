<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Closure;
use Hypervel\Contracts\Validation\CompilableRules;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Contracts\Validation\ValidationRule as ValidationRuleContract;
use Hypervel\Data\Support\Validation\ValidationRule;
use Hypervel\Validation\ConditionalRules;
use Stringable;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Rule extends ValidationRule
{
    /** @var array<array|Closure|CompilableRules|ConditionalRules|RuleContract|string|Stringable|ValidationRule|ValidationRuleContract> */
    protected array $rules = [];

    /**
     * Create a custom rule attribute.
     *
     * Each rule takes any form the validator accepts, including closures, fluent rule objects and conditional rules.
     */
    public function __construct(
        string|array|Closure|Stringable|CompilableRules|ConditionalRules|ValidationRule|RuleContract|ValidationRuleContract ...$rules
    ) {
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
