<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Support\Number;

class DatabasePresenceVerifier implements PresenceVerifierInterface
{
    /**
     * Create a new database presence verifier.
     */
    public function __construct(protected ConnectionResolverInterface $db)
    {
    }

    /**
     * Count the number of objects matching the resolved presence query.
     */
    public function getCount(PresenceQuery $query, mixed $value): int
    {
        $builder = $this->addComparison($this->table($query), $query->column, '=', $this->prepareValue($query->column, $value));

        return $this->addQueryConditions($builder, $query)->count();
    }

    /**
     * Count the distinct values matching the resolved presence query.
     */
    public function getMultiCount(PresenceQuery $query, array $values): int
    {
        $values = array_map(fn (mixed $value): mixed => $this->prepareValue($query->column, $value), $values);
        $booleans = array_filter($values, is_bool(...));

        if ($booleans === [] || count($booleans) === count($values)) {
            $builder = $this->addWhereIn($this->table($query), $query->column, $values);

            // Booleans compare natively, so distinct matches are counted by their stored value.
            $column = $booleans === [] ? $this->prepareColumn($builder, $query->column) : $query->column;

            return $this->addQueryConditions($builder, $query)->distinct()->count($column);
        }

        // Count each group in one statement, so a row matched by both a requested boolean and a text value
        // satisfies only one. SQLite's boolean comparison also matches stored integers, which a text value
        // claims; elsewhere a JSON boolean that a text value matches stays with the boolean.
        $builder = $this->addQueryConditions($this->table($query), $query);
        $texts = array_values(array_diff_key($values, $booleans));
        $booleans = array_values($booleans);

        $booleanMatches = (clone $builder)->whereIn($query->column, $booleans);
        $textMatches = $this->addWhereIn(clone $builder, $query->column, $texts);

        if ($builder->getGrammar() instanceof SQLiteGrammar) {
            // Only the text SQLite extracts from a stored numeric zero or one competes with a native boolean.
            $overlaps = array_values(array_intersect(['0', '1', '0.0', '1.0'], array_map(
                $this->jsonText(...),
                array_filter($texts, static fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value)),
            )));

            if ($overlaps !== []) {
                $this->addWhereIn($booleanMatches, $query->column, $overlaps, not: true);
            }
        } else {
            $textMatches->whereNotIn($query->column, $booleans);
        }

        $booleanMatches->distinct()->select($query->column);
        $textMatches->distinct()->select($this->prepareColumn($builder, $query->column));

        return $booleanMatches->unionAll($textMatches)->count();
    }

    /**
     * Get the distinct existing candidate values for a batch.
     *
     * @return array<int, mixed>
     */
    public function getExistingValues(PresenceQuery $query, array $values): array
    {
        $values = array_map(fn (mixed $value): mixed => $this->prepareValue($query->column, $value), $values);
        $builder = $this->addWhereIn($this->table($query), $query->column, $values);

        // Return the compared text, so batch facts match the values the query compared.
        /** @var array<int, mixed> */
        return $this->addQueryConditions($builder, $query)->distinct()->pluck($this->prepareColumn($builder, $query->column))->all();
    }

    /**
     * Add exclusions and predicates without storing invocation state on the verifier.
     */
    protected function addQueryConditions(Builder $builder, PresenceQuery $query): Builder
    {
        if ($query->excludeId !== null) {
            $this->addComparison($builder, $query->idColumn ?: 'id', '<>', $query->excludeId);
        }

        return $this->addConditions($builder, $query->conditions);
    }

    /**
     * Prepare a column for comparison with a string value.
     *
     * SQLite extracts JSON values with their JSON type, so a JSON number never
     * equals a string. Other databases already extract JSON values as text.
     */
    protected function prepareColumn(Builder $query, string $column): ExpressionContract|string
    {
        if (! str_contains($column, '->') || ! $query->getGrammar() instanceof SQLiteGrammar) {
            return $column;
        }

        return new Expression('cast(' . $query->getGrammar()->wrap($column) . ' as text)');
    }

    /**
     * Prepare the value being validated for comparison with its database column.
     */
    protected function prepareValue(string $column, mixed $value): mixed
    {
        // Unlike Laravel's value-only hook, the column keeps JSON booleans typed
        // through the same normalizer used by condition operands.
        return DatabaseCondition::prepareValue($column, $value);
    }

    /**
     * Add an equality comparison with a prepared value.
     *
     * PostgreSQL rejects integers outside the column's range. Presence rules have no column
     * type, so apply `integer` and a numeric range such as `between:0,2147483647` first.
     *
     * @param '!='|'<>'|'=' $operator
     */
    protected function addComparison(Builder $query, string $column, string $operator, mixed $value): Builder
    {
        if ($value === null || is_bool($value) || ! str_contains($column, '->') || ! $query->getGrammar() instanceof SQLiteGrammar) {
            return $query->where($column, $operator, $value);
        }

        return $this->addWhereIn($query, $column, [$value], not: $operator !== '=');
    }

    /**
     * Add a membership comparison with prepared values, keeping native JSON booleans.
     */
    protected function addWhereIn(Builder $query, string $column, array $values, bool $not = false): Builder
    {
        $grammar = str_contains($column, '->') ? $query->getGrammar() : null;
        $texts = $grammar instanceof SQLiteGrammar
            ? array_filter($values, static fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value))
            : [];

        if ($texts === []) {
            return $not ? $query->whereNotIn($column, $values) : $query->whereIn($column, $values);
        }

        // Booleans and nulls keep their native comparison, including a null member's effect on NOT IN.
        $others = array_values(array_diff_key($values, $texts));

        $callback = function (Builder $query) use ($grammar, $column, $texts, $others): void {
            $query->where(fn (Builder $query) => $this->whereJsonText($query, $grammar, $column, $texts));

            if ($others !== []) {
                $query->orWhereIn($column, $others);
            }
        };

        return $not ? $query->whereNot($callback) : $query->where($callback);
    }

    /**
     * Get the text a value compares as against a SQLite JSON value.
     */
    protected function jsonText(int|float|string $value): string
    {
        return is_float($value) ? Number::toString($value) : (string) $value;
    }

    /**
     * Match SQLite JSON values by their text, as other databases extract them.
     *
     * Strings and integers compare with the extracted value so its index stays usable.
     */
    protected function whereJsonText(Builder $query, SQLiteGrammar $grammar, string $column, array $values): void
    {
        $strings = [];
        $integers = [];
        $decimals = [];

        foreach ($values as $value) {
            $value = $this->jsonText($value);

            // SQLite writes every finite real with a decimal point or exponent.
            if (is_numeric($value) && strpbrk($value, '.eE') !== false) {
                $decimals[] = $value;

                continue;
            }

            $strings[] = $value;

            // A JSON integer extracts as a number, which never equals a string.
            if ((string) (int) $value === $value) {
                $integers[] = (int) $value;
            }
        }

        if ($strings !== []) {
            $query->orWhereIn($column, $strings);
        }

        if ($integers !== []) {
            // Reals, integers too large for SQLite and booleans also extract as numbers, so only stored integers match.
            $query->orWhere(fn (Builder $query) => $query->whereIntegerInRaw($column, $integers)
                ->whereRaw('typeof(' . $grammar->wrap($column) . ") = 'integer'")
                ->whereRaw($grammar->compileJsonType($column) . " = 'integer'"));
        }

        if ($decimals !== []) {
            $query->orWhereIn($this->prepareColumn($query, $column), $decimals);
        }
    }

    /**
     * Apply the declared predicates and explicit query callbacks.
     */
    protected function addConditions(Builder $query, DatabaseConditions $conditions): Builder
    {
        foreach ($conditions->all() as $condition) {
            if ($condition instanceof Closure) {
                $query->where($condition);
            } else {
                $this->addWhere($query, $condition);
            }
        }

        return $query;
    }

    /**
     * Apply one typed predicate without interpreting its value as a string token.
     */
    protected function addWhere(Builder $query, DatabaseCondition $condition): void
    {
        match ($condition->operator) {
            DatabaseConditionOperator::Equals => $this->addComparison($query, $condition->column, '=', $condition->value),
            DatabaseConditionOperator::NotEquals => $this->addComparison($query, $condition->column, '!=', $condition->value),
            DatabaseConditionOperator::IsNull => $query->whereNull($condition->column),
            DatabaseConditionOperator::IsNotNull => $query->whereNotNull($condition->column),
            DatabaseConditionOperator::In => $this->addWhereIn($query, $condition->column, $this->prepareConditionValues($condition)),
            DatabaseConditionOperator::NotIn => $this->addWhereIn($query, $condition->column, $this->prepareConditionValues($condition), not: true),
        };
    }

    /**
     * Normalize the members of a membership condition like an equality operand.
     */
    protected function prepareConditionValues(DatabaseCondition $condition): array
    {
        // Delegated rules pass their declared list, which is read when the query runs.
        $values = $condition->value instanceof Arrayable ? $condition->value->toArray() : $condition->value;

        return array_map(fn (mixed $value): mixed => DatabaseCondition::prepareValue($condition->column, $value), $values);
    }

    /**
     * Get a write-connection query builder for the requested table.
     */
    protected function table(PresenceQuery $query): Builder
    {
        return $this->db->connection($query->connection)->table($query->collection)->useWritePdo();
    }
}
