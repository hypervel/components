<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class MissingUnless implements ParameterizedRule
{
    use FormatsParameters;

    protected string $anotherField;

    protected array $values;

    /**
     * Create a new missing_unless rule instance.
     */
    public function __construct(string $anotherField, string|int|float|bool|array|null $values)
    {
        $this->anotherField = $anotherField;
        $this->values = is_array($values) ? $values : array_slice(func_get_args(), 1);
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['missing_unless', $this->anotherField, ...$this->formatConditionalValues($this->values)];
    }
}
