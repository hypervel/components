<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use BackedEnum;
use Stringable;

use function Hypervel\Support\enum_value;

final readonly class DatabaseCondition
{
    public mixed $value;

    /**
     * Create a database predicate with an explicit operator.
     */
    public function __construct(
        public string $column,
        public DatabaseConditionOperator $operator,
        mixed $value = null,
    ) {
        $this->value = match ($operator) {
            DatabaseConditionOperator::IsNull, DatabaseConditionOperator::IsNotNull => null,
            DatabaseConditionOperator::In, DatabaseConditionOperator::NotIn => $value instanceof BackedEnum ? [$value->value] : $value,
            default => self::prepareValue($column, $value instanceof Stringable ? (string) $value : $value),
        };
    }

    /**
     * Normalize a comparison operand without losing JSON boolean semantics.
     */
    public static function prepareValue(string $column, mixed $value): mixed
    {
        $value = enum_value($value);

        return match (true) {
            is_bool($value) && str_contains($column, '->') => $value,
            is_bool($value) => (string) (int) $value,
            is_int($value) => (string) $value,
            default => $value,
        };
    }
}
