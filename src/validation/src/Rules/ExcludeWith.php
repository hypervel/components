<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class ExcludeWith implements ParameterizedRule
{
    use FormatsParameters;

    /**
     * Create a new exclude_with rule instance.
     */
    public function __construct(protected string $anotherField)
    {
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['exclude_with', $this->anotherField];
    }
}
