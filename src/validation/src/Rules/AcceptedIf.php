<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsParameters;

class AcceptedIf implements ParameterizedRule
{
    use FormatsParameters;

    protected string $anotherField;

    /**
     * @var array<array-key, null|bool|float|int|string>
     */
    protected array $values;

    /**
     * Create a new accepted_if rule instance.
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
        $values = array_map(
            static fn (string|int|float|bool|null $value): string|false => is_string($value) ? $value : json_encode($value),
            $this->values,
        );

        return ['accepted_if', $this->anotherField, ...array_values($values)];
    }
}
