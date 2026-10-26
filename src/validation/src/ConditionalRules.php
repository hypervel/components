<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Fluent;
use Stringable;

class ConditionalRules
{
    /**
     * The boolean condition indicating if the rules should be added to the attribute.
     */
    protected bool|Closure $condition;

    /**
     * Create a new conditional rules instance.
     *
     * @param bool|callable $condition the boolean condition indicating if the rules should be added to the attribute
     * @param array|Closure|Rule|string|Stringable|ValidationRule $rules the rules to be added to the attribute
     * @param array|Closure|Rule|string|Stringable|ValidationRule $defaultRules the rules to be added to the attribute if the condition fails
     */
    public function __construct(
        bool|callable $condition,
        protected array|Closure|Rule|string|Stringable|ValidationRule $rules,
        protected array|Closure|Rule|string|Stringable|ValidationRule $defaultRules = []
    ) {
        $this->condition = is_bool($condition) ? $condition : $condition(...);
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
     */
    public function rules(array $data = []): array
    {
        $rules = $this->rules instanceof Closure ? ($this->rules)(new Fluent($data)) : $this->rules;

        return is_string($rules) ? explode('|', $rules) : Arr::wrap($rules);
    }

    /**
     * Get the default rules.
     */
    public function defaultRules(array $data = []): array
    {
        $rules = $this->defaultRules instanceof Closure ? ($this->defaultRules)(new Fluent($data)) : $this->defaultRules;

        return is_string($rules) ? explode('|', $rules) : Arr::wrap($rules);
    }
}
