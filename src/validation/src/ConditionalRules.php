<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Support\Fluent;

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
     * @param array|Closure|Rule|string|ValidationRule $rules the rules to be added to the attribute
     * @param array|Closure|Rule|string|ValidationRule $defaultRules the rules to be added to the attribute if the condition fails
     */
    public function __construct(
        bool|callable $condition,
        protected array|Closure|Rule|string|ValidationRule $rules,
        protected array|Closure|Rule|string|ValidationRule $defaultRules = []
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
