<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Contracts\Support\Arrayable;

use function Hypervel\Support\enum_value;

final readonly class DatabaseConditions
{
    /** @var list<Closure|DatabaseCondition> */
    private array $conditions;

    /**
     * Create an ordered set of database predicates and query callbacks.
     */
    public function __construct(DatabaseCondition|Closure ...$conditions)
    {
        $this->conditions = array_values($conditions);
    }

    /**
     * Decode the extra column/value pairs from a legacy rule.
     */
    public static function fromParameters(array $parameters): self
    {
        $conditions = [];

        for ($index = 0, $count = count($parameters); $index < $count; $index += 2) {
            $value = $parameters[$index + 1];
            [$operator, $operand] = match (true) {
                $value === null || $value === 'NULL' => [DatabaseConditionOperator::IsNull, null],
                $value === 'NOT_NULL' => [DatabaseConditionOperator::IsNotNull, null],
                is_string($value) && str_starts_with($value, '!') => [DatabaseConditionOperator::NotEquals, mb_substr($value, 1)],
                default => [DatabaseConditionOperator::Equals, $value],
            };
            $conditions[] = new DatabaseCondition($parameters[$index], $operator, $operand);
        }

        return new self(...$conditions);
    }

    /**
     * Get the declared predicates and callbacks in order.
     *
     * @return list<Closure|DatabaseCondition>
     */
    public function all(): array
    {
        return $this->conditions;
    }

    /**
     * Capture membership values and detach their references for the current run.
     */
    public function prepare(): self
    {
        $prepared = null;

        foreach ($this->conditions as $index => $condition) {
            if ($condition instanceof DatabaseCondition
                && (is_array($condition->value) || $condition->value instanceof Arrayable)
            ) {
                $values = $condition->value instanceof Arrayable ? $condition->value->toArray() : $condition->value;
                $prepared ??= $this->conditions;
                $prepared[$index] = new DatabaseCondition($condition->column, $condition->operator, array_map(enum_value(...), $values));
            }
        }

        return $prepared === null ? $this : new self(...$prepared);
    }

    /**
     * Get typed condition data without invoking query callbacks.
     */
    public function toArray(): array
    {
        return array_map(
            static fn (DatabaseCondition|Closure $condition): array|Closure => $condition instanceof Closure
                ? $condition
                : [$condition->column, $condition->operator->value, $condition->value],
            $this->conditions,
        );
    }

    /**
     * Get a plain cache description, or null for executable or opaque operands.
     */
    public function cacheData(): ?array
    {
        $conditions = [];

        foreach ($this->conditions as $condition) {
            if ($condition instanceof Closure) {
                return null;
            }

            $value = $condition->value;

            if (is_array($value)) {
                if (! array_all($value, static fn (mixed $item): bool => is_scalar($item) || $item === null)) {
                    return null;
                }
            } elseif (! is_scalar($value) && $value !== null) {
                return null;
            }

            $conditions[] = [$condition->column, $condition->operator->value, $value];
        }

        return $conditions;
    }
}
