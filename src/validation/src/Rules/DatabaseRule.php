<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use BackedEnum;
use Closure;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Validation\DatabaseCondition;
use Hypervel\Validation\DatabaseConditionOperator;
use Hypervel\Validation\DatabaseConditions;

trait DatabaseRule
{
    /**
     * The table to run the query against.
     */
    protected string $table;

    /**
     * The column to check on.
     */
    protected string $column;

    /**
     * The extra where clauses for the query.
     *
     * @var list<DatabaseCondition>
     */
    protected array $wheres = [];

    /**
     * The array of custom query callbacks.
     */
    protected array $using = [];

    /**
     * Create a new rule instance.
     */
    public function __construct(string $table, string $column = 'NULL')
    {
        $this->column = $column;

        $this->table = $this->resolveTableName($table);
    }

    /**
     * Resolves the name of the table from the given string.
     */
    public function resolveTableName(string $table): string
    {
        if (! str_contains($table, '\\') || ! class_exists($table)) {
            return $table;
        }

        if (is_subclass_of($table, Model::class)) {
            $model = new $table;

            if (str_contains($model->getTable(), '.')) {
                return $table;
            }

            return implode('.', array_map(function (string $part) {
                return trim($part, '.');
            }, array_filter([$model->getConnectionName(), $model->getTable()])));
        }

        return $table;
    }

    /**
     * Set a "where" constraint on the query.
     *
     * @return $this
     */
    public function where(Closure|string $column, mixed $value = null): static
    {
        if ($value instanceof Arrayable || is_array($value)) {
            return $this->whereIn($column, $value);
        }

        if ($column instanceof Closure) {
            return $this->using($column);
        }

        if (is_null($value)) {
            return $this->whereNull($column);
        }

        $this->wheres[] = new DatabaseCondition($column, DatabaseConditionOperator::Equals, $value);

        return $this;
    }

    /**
     * Set a "where not" constraint on the query.
     *
     * @return $this
     */
    public function whereNot(string $column, mixed $value): static
    {
        if ($value instanceof Arrayable || is_array($value)) {
            return $this->whereNotIn($column, $value);
        }

        $this->wheres[] = new DatabaseCondition($column, DatabaseConditionOperator::NotEquals, $value ?? '');

        return $this;
    }

    /**
     * Set a "where null" constraint on the query.
     *
     * @return $this
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = new DatabaseCondition($column, DatabaseConditionOperator::IsNull);

        return $this;
    }

    /**
     * Set a "where not null" constraint on the query.
     *
     * @return $this
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = new DatabaseCondition($column, DatabaseConditionOperator::IsNotNull);

        return $this;
    }

    /**
     * Set a "where in" constraint on the query.
     *
     * @return $this
     */
    public function whereIn(string $column, array|Arrayable|BackedEnum $values): static
    {
        $this->wheres[] = new DatabaseCondition($column, DatabaseConditionOperator::In, $values);

        return $this;
    }

    /**
     * Set a "where not in" constraint on the query.
     *
     * @return $this
     */
    public function whereNotIn(string $column, array|Arrayable|BackedEnum $values): static
    {
        $this->wheres[] = new DatabaseCondition($column, DatabaseConditionOperator::NotIn, $values);

        return $this;
    }

    /**
     * Ignore soft deleted models during the existence checks.
     *
     * @return $this
     */
    public function withoutTrashed(string $deletedAtColumn = 'deleted_at'): static
    {
        $this->whereNull($deletedAtColumn);

        return $this;
    }

    /**
     * Only include soft deleted models during the existence check.
     *
     * @return $this
     */
    public function onlyTrashed(string $deletedAtColumn = 'deleted_at'): static
    {
        $this->whereNotNull($deletedAtColumn);

        return $this;
    }

    /**
     * Register a custom query callback.
     *
     * @return $this
     */
    public function using(Closure $callback): static
    {
        $this->using[] = $callback;

        return $this;
    }

    /**
     * Get the custom query callbacks for the rule.
     */
    public function queryCallbacks(): array
    {
        return $this->using;
    }

    /**
     * Get the extra conditions for the presence verifier.
     */
    public function queryConditions(): DatabaseConditions
    {
        // Unlike Laravel, read the callback hook so subclass overrides remain effective.
        return new DatabaseConditions(...[...$this->wheres, ...$this->queryCallbacks()]);
    }

    /**
     * Format the where clauses.
     */
    protected function formatWheres(): string
    {
        $wheres = [];

        foreach ($this->queryConditions()->all() as $condition) {
            if ($condition instanceof Closure) {
                continue;
            }

            $operand = is_bool($condition->value) ? (int) $condition->value : $condition->value;

            // Membership predicates, like callbacks, have no legacy string representation.
            $value = match ($condition->operator) {
                DatabaseConditionOperator::Equals => (string) $operand,
                DatabaseConditionOperator::NotEquals => '!' . $operand,
                DatabaseConditionOperator::IsNull => 'NULL',
                DatabaseConditionOperator::IsNotNull => 'NOT_NULL',
                DatabaseConditionOperator::In, DatabaseConditionOperator::NotIn => null,
            };

            if ($value !== null) {
                $wheres[] = $condition->column . ',"' . str_replace('"', '""', $value) . '"';
            }
        }

        return implode(',', $wheres);
    }
}
