<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules\Concerns;

trait FormatsParameters
{
    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        $parameters = $this->toArray();
        $rule = array_shift($parameters);

        return $rule . ($parameters ? ':' . $this->formatParameters($parameters) : '');
    }

    /**
     * Format conditional comparison values without rounding floats.
     *
     * @return list<string>
     */
    protected function formatConditionalValues(array $values): array
    {
        return array_map(static fn (string|int|float|bool|null $value): string => match (true) {
            is_null($value) => 'null',
            $value === true => 'true',
            $value === false => 'false',
            // JSON preserves finite precision; non-finite values retain their literal representation.
            is_float($value) => json_encode($value) ?: (string) $value,
            default => (string) $value,
        }, array_values($values));
    }

    /**
     * Format parameters for a legacy validation rule string.
     */
    protected function formatParameters(array $parameters): string
    {
        return implode(',', array_map(static function (mixed $parameter): string {
            $parameter = (string) $parameter;

            return str_contains($parameter, ',') || str_contains($parameter, '"')
                ? '"' . str_replace('"', '""', $parameter) . '"'
                : $parameter;
        }, $parameters));
    }
}
