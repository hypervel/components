<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Validation\Contracts\CompilableRules;
use stdClass;

class NestedRules implements CompilableRules
{
    /**
     * Create a new nested rule instance.
     *
     * @param Closure $callback the callback to execute
     */
    public function __construct(
        protected Closure $callback
    ) {
    }

    /**
     * Compile the callback into an array of rules.
     */
    public function compile(string $attribute, mixed $value, mixed $data = null, mixed $context = null): stdClass
    {
        $rules = call_user_func($this->callback, $value, $attribute, $data, $context);

        return Rule::compile($attribute, $rules, $data);
    }
}
