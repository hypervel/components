<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Support\Fluent;
use Hypervel\Contracts\Validation\InvokableRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;

class ConditionalRules
{
    /**
     * Create a new conditional rules instance.
     *
     * @param bool|Closure $condition the boolean condition indicating if the rules should be added to the attribute
     * @param array|Closure|InvokableRule|Rule|string|ValidationRule $rules the rules to be added to the attribute
     * @param array|Closure|InvokableRule|Rule|string|ValidationRule $defaultRules the rules to be added to the attribute if the condition fails
     */
    public function __construct(
        protected bool|Closure $condition,
        protected array|Closure|InvokableRule|Rule|string|ValidationRule $rules,
        protected array|Closure|InvokableRule|Rule|string|ValidationRule $defaultRules = []
    ) {
    }

    /**
     * Determine if the conditional rules should be added.
     */
    public function passes(array $data = []): bool
    {
        return is_callable($this->condition)
            ? call_user_func($this->condition, new Fluent($data))
            : $this->condition;
    }

    /**
     * Get the rules.
     *
     * @return array
     */
    public function rules(array $data = []): mixed
    {
        return is_string($this->rules)
            ? explode('|', $this->rules)
            : value($this->rules, new Fluent($data));
    }

    /**
     * Get the default rules.
     *
     * @return array
     */
    public function defaultRules(array $data = []): mixed
    {
        return is_string($this->defaultRules)
            ? explode('|', $this->defaultRules)
            : value($this->defaultRules, new Fluent($data));
    }
}
