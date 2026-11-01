<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class InArray implements ParameterizedRule
{
    use FormatsParameters;

    /**
     * Create a new in_array rule instance.
     */
    public function __construct(protected string $otherField)
    {
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['in_array', $this->otherField];
    }
}
