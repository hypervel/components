<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use BackedEnum;
use Closure;
use Hyperf\Contract\Arrayable;
use Hyperf\Database\Model\Model;
use Hypervel\Support\Collection;

use function Hypervel\Support\enum_value;

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
            $model = new $table();

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

        $value = enum_value($value);

        $this->wheres[] = compact('column', 'value');

        return $this;
    }

    /**
     * Set a "where not" constraint on the query.
     */
    public function whereNot(string $column, mixed $value): static
    {
        if ($value instanceof Arrayable || is_array($value)) {
            return $this->whereNotIn($column, $value);
        }

        $value = enum_value($value);

        return $this->where($column, '!' . $value);
    }

    /**
     * Set a "where null" constraint on the query.
     */
    public function whereNull(string $column): static
    {
        return $this->where($column, 'NULL');
    }

    /**
     * Set a "where not null" constraint on the query.
     */
    public function whereNotNull(string $column): static
    {
        return $this->where($column, 'NOT_NULL');
    }

    /**
     * Set a "where in" constraint on the query.
     */
    public function whereIn(string $column, array|Arrayable|BackedEnum $values): static
    {
        return $this->where(function ($query) use ($column, $values) {
            $query->whereIn($column, $values);
        });
    }

    /**
     * Set a "where not in" constraint on the query.
     */
    public function whereNotIn(string $column, array|Arrayable|BackedEnum $values): static
    {
        return $this->where(function ($query) use ($column, $values) {
            $query->whereNotIn($column, $values);
        });
    }

    /**
     * Ignore soft deleted models during the existence check.s.
     */
    public function withoutTrashed(string $deletedAtColumn = 'deleted_at'): static
    {
        $this->whereNull($deletedAtColumn);

        return $this;
    }

    /**
     * Only include soft deleted models during the existence check.
     */
    public function onlyTrashed(string $deletedAtColumn = 'deleted_at'): static
    {
        $this->whereNotNull($deletedAtColumn);

        return $this;
    }

    /**
     * Register a custom query callback.
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
     * Format the where clauses.
     */
    protected function formatWheres(): string
    {
        return (new Collection($this->wheres))->map(function ($where) {
            return $where['column'] . ',"' . str_replace('"', '""', (string) $where['value']) . '"';
        })->implode(',');
    }
}
