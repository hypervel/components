<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class InArrayKeys implements ParameterizedRule
{
    use FormatsParameters;

    protected array $keys;

    /**
     * Create a new in_array_keys rule instance.
     */
    public function __construct(array|string $keys)
    {
        $this->keys = is_array($keys) ? $keys : func_get_args();
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['in_array_keys', ...array_values($this->keys)];
    }
}
