<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hypervel\Database\MySqlConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\IndexHint;
use Hypervel\Database\Query\JoinLateralClause;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Override;

/**
 * @property MySqlConnection $connection
 */
class MySqlGrammar extends Grammar
{
    /**
     * The grammar specific operators.
     *
     * @var string[]
     */
    protected array $operators = ['sounds like'];

    /**
     * Compile the query timeout for a complete select statement.
     */
    #[Override]
    protected function compileSelectTimeout(Builder $query, string $sql): string
    {
        if ($query->timeout === null) {
            return $sql;
        }

        $milliseconds = $query->timeout * 1000;

        return preg_replace(
            '/^(\(*)select\b/i',
            '${1}select /*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */',
            $sql,
            1
        );
    }

    /**
     * Compile a "where binary" clause.
     */
    protected function whereBinary(Builder $query, array $where): string
    {
        $operator = $where['not'] ? '!=' : '=';

        return $this->wrap($where['column']) . ' ' . $operator . ' cast(' . $this->parameter($where['value']) . ' as binary)';
    }

    /**
     * Compile a "where like" clause.
     */
    protected function whereLike(Builder $query, array $where): string
    {
        $where['operator'] = $where['not'] ? 'not like' : 'like';

        if ($where['caseSensitive']) {
            return $this->wrap($where['column']) . ' ' . $where['operator'] . ' cast(' . $this->parameter($where['value']) . ' as binary)';
        }

        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a "where null safe equals" clause.
     */
    protected function whereNullSafeEquals(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' <=> ' . $this->parameter($where['value']);
    }

    /**
     * Add a "where null" clause to the query.
     */
    protected function whereNull(Builder $query, array $where): string
    {
        $columnValue = (string) $this->getValue($where['column']);

        if ($this->isJsonSelector($columnValue)) {
            [$field, $path] = $this->wrapJsonFieldAndPath($columnValue);

            return '(json_extract(' . $field . $path . ') is null OR json_type(json_extract(' . $field . $path . ')) = \'NULL\')';
        }

        return parent::whereNull($query, $where);
    }

    /**
     * Add a "where not null" clause to the query.
     */
    protected function whereNotNull(Builder $query, array $where): string
    {
        $columnValue = (string) $this->getValue($where['column']);

        if ($this->isJsonSelector($columnValue)) {
            [$field, $path] = $this->wrapJsonFieldAndPath($columnValue);

            return '(json_extract(' . $field . $path . ') is not null AND json_type(json_extract(' . $field . $path . ')) != \'NULL\')';
        }

        return parent::whereNotNull($query, $where);
    }

    /**
     * Compile a "where fulltext" clause.
     */
    public function whereFullText(Builder $query, array $where): string
    {
        $columns = $this->columnize($where['columns']);

        $value = $this->parameter($where['value']);

        $mode = ($where['options']['mode'] ?? []) === 'boolean'
            ? ' in boolean mode'
            : ' in natural language mode';

        $expanded = ($where['options']['expanded'] ?? []) && ($where['options']['mode'] ?? []) !== 'boolean'
            ? ' with query expansion'
            : '';

        return "match ({$columns}) against (" . $value . "{$mode}{$expanded})";
    }

    /**
     * Compile the index hints for the query.
     *
     * @throws InvalidArgumentException
     */
    protected function compileIndexHint(Builder $query, IndexHint $indexHint): string
    {
        $index = $indexHint->index;

        $indexes = array_map('trim', explode(',', $index));

        foreach ($indexes as $i) {
            if (! preg_match('/^[a-zA-Z0-9_$]+$/', $i)) {
                throw new InvalidArgumentException('Index name contains invalid characters.');
            }
        }

        return match ($indexHint->type) {
            'hint' => "use index ({$index})",
            'force' => "force index ({$index})",
            default => "ignore index ({$index})",
        };
    }

    /**
     * Compile a group limit clause.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        return $this->useLegacyGroupLimit($query)
            ? $this->compileLegacyGroupLimit($query)
            : parent::compileGroupLimit($query);
    }

    /**
     * Determine whether to use a legacy group limit clause for MySQL < 8.0.
     */
    public function useLegacyGroupLimit(Builder $query): bool
    {
        $version = $query->getConnection()->getServerVersion();

        // @phpstan-ignore method.notFound (MySqlGrammar is only used with MySqlConnection which has isMaria())
        return ! $query->getConnection()->isMaria() && version_compare($version, '8.0.11', '<');
    }

    /**
     * Compile a group limit clause for MySQL < 8.0.
     *
     * Derived from https://softonsofa.com/tweaking-eloquent-relations-how-to-get-n-related-models-per-parent/.
     */
    protected function compileLegacyGroupLimit(Builder $query): string
    {
        $limit = (int) $query->groupLimit['value'];
        $offset = $query->offset;

        if (isset($offset)) {
            $offset = (int) $offset;
            $limit += $offset;

            $query->offset = null;
        }

        $column = last(explode('.', $query->groupLimit['column']));
        $column = $this->wrap($column);

        $partition = ', @hypervel_row := if(@hypervel_group = ' . $column . ', @hypervel_row + 1, 1) as `hypervel_row`';
        $partition .= ', @hypervel_group := ' . $column;

        $orders = (array) $query->orders;

        array_unshift($orders, [
            'column' => $query->groupLimit['column'],
            'direction' => 'asc',
        ]);

        $query->orders = $orders;

        $components = $this->compileComponents($query);

        $sql = $this->concatenate($components);

        $from = '(select @hypervel_row := 0, @hypervel_group := 0) as `hypervel_vars`, (' . $sql . ') as `hypervel_table`';

        $sql = 'select `hypervel_table`.*' . $partition . ' from ' . $from . ' having `hypervel_row` <= ' . $limit;

        if (isset($offset)) {
            $sql .= ' and `hypervel_row` > ' . $offset;
        }

        return $sql . ' order by `hypervel_row`';
    }

    /**
     * Compile an insert ignore statement into SQL.
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        return Str::replaceFirst('insert', 'insert ignore', $this->compileInsert($query, $values));
    }

    /**
     * Compile an insert ignore statement using a subquery into SQL.
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql): string
    {
        return Str::replaceFirst('insert', 'insert ignore', $this->compileInsertUsing($query, $columns, $sql));
    }

    /**
     * Compile a "JSON contains" statement into SQL.
     */
    protected function compileJsonContains(string $column, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_contains(' . $field . ', ' . $value . $path . ')';
    }

    /**
     * Compile a "JSON overlaps" statement into SQL.
     */
    protected function compileJsonOverlaps(string $column, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_overlaps(' . $field . ', ' . $value . $path . ')';
    }

    /**
     * Compile a "JSON contains key" statement into SQL.
     */
    protected function compileJsonContainsKey(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'ifnull(json_contains_path(' . $field . ', \'one\'' . $path . '), 0)';
    }

    /**
     * Compile a "JSON length" statement into SQL.
     */
    protected function compileJsonLength(string $column, string $operator, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_length(' . $field . $path . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a "JSON value cast" statement into SQL.
     */
    public function compileJsonValueCast(string $value): string
    {
        return 'cast(' . $value . ' as json)';
    }

    /**
     * Compile the random statement into SQL.
     *
     * @throws InvalidArgumentException
     */
    public function compileRandom(string|int $seed): string
    {
        if ($seed === '') {
            return 'RAND()';
        }

        if (! is_numeric($seed)) {
            throw new InvalidArgumentException('The seed value must be numeric.');
        }

        return 'RAND(' . (int) $seed . ')';
    }

    /**
     * Compile the lock into SQL.
     */
    protected function compileLock(Builder $query, bool|string $value): string
    {
        if (! is_string($value)) {
            return $value ? 'for update' : 'lock in share mode';
        }

        return $value;
    }

    /**
     * Compile an insert statement into SQL.
     */
    public function compileInsert(Builder $query, array $values): string
    {
        if (empty($values)) {
            $values = [[]];
        }

        return parent::compileInsert($query, $values);
    }

    /**
     * Compile the columns for an update statement.
     */
    protected function compileUpdateColumns(Builder $query, array $values): string
    {
        if (isset($query->joins)) {
            // Joined updates cannot rely on earlier assignments to the same column.
            return (new Collection($this->groupJsonColumnsForUpdate($values)))
                ->map(function (array $group, string $column): string {
                    return $this->isJsonSelector(array_key_first($group))
                        ? $this->compileJsonUpdateColumn($column, $group)
                        : $this->wrap($column) . ' = ' . $this->parameter(reset($group));
                })->implode(', ');
        }

        return (new Collection($values))->map(function ($value, $key) {
            if ($this->isJsonSelector($key)) {
                return $this->compileJsonUpdateColumn(explode('->', $key, 2)[0], [$key => $value]);
            }

            return $this->wrap($key) . ' = ' . $this->parameter($value);
        })->implode(', ');
    }

    /**
     * Group update values by column in order of first appearance.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function groupJsonColumnsForUpdate(array $values): array
    {
        $groups = [];

        foreach ($values as $key => $value) {
            // Joined updates can assign columns belonging to different tables.
            $column = explode('->', $key, 2)[0];

            $groups[$column][$key] = $value;
        }

        return $groups;
    }

    /**
     * Compile an "upsert" statement into SQL.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $useUpsertAlias = $query->connection->getConfig('use_upsert_alias');

        $sql = $this->compileInsert($query, $values);

        if ($useUpsertAlias) {
            $sql .= ' as hypervel_upsert_alias';
        }

        $sql .= ' on duplicate key update ';

        $columns = (new Collection($update))->map(function ($value, $key) use ($useUpsertAlias) {
            if (! is_numeric($key)) {
                return $this->wrap($key) . ' = ' . $this->parameter($value);
            }

            return $useUpsertAlias
                ? $this->wrap($value) . ' = ' . $this->wrap('hypervel_upsert_alias') . '.' . $this->wrap($value)
                : $this->wrap($value) . ' = values(' . $this->wrap($value) . ')';
        })->implode(', ');

        return $sql . $columns;
    }

    /**
     * Compile a "lateral join" clause.
     */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        return trim("{$join->type} join lateral {$expression} on true");
    }

    /**
     * Determine if the grammar supports straight joins.
     */
    protected function supportsStraightJoins(): bool
    {
        return true;
    }

    /**
     * Prepare a JSON column being updated using the JSON_SET function.
     */
    protected function compileJsonUpdateColumn(string $key, array $values): string
    {
        $field = $this->wrap($key);
        $paths = [];

        foreach ($values as $path => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = $this->compileJsonValueCast('?');
            } else {
                $value = $this->parameter($value);
            }

            $path = $this->wrapJsonPath(explode('->', $path, 2)[1]);
            $paths[] = ', ' . $path . ', ' . $value;
        }

        return "{$field} = json_set({$field}" . implode('', $paths) . ')';
    }

    /**
     * Compile an update statement without joins into SQL.
     */
    protected function compileUpdateWithoutJoins(Builder $query, string $table, string $columns, string $where): string
    {
        $sql = parent::compileUpdateWithoutJoins($query, $table, $columns, $where);

        if (! empty($query->orders)) {
            $sql .= ' ' . $this->compileOrders($query, $query->orders);
        }

        if (isset($query->limit)) {
            $sql .= ' ' . $this->compileLimit($query, $query->limit);
        }

        return $sql;
    }

    /**
     * Prepare the bindings for an update statement.
     *
     * Booleans, integers, and doubles are inserted into JSON updates as raw values.
     */
    #[Override]
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        $values = (new Collection($values))
            ->reject(fn ($value, $column) => $this->isJsonSelector($column) && is_bool($value))
            ->map(fn ($value) => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value)
            ->all();

        return parent::prepareBindingsForUpdate($bindings, $values);
    }

    /**
     * Prepare the bindings for an update statement with joins.
     */
    public function prepareBindingsForUpdateWithJoins(array $bindings, array $values): array
    {
        $ordered = [];

        foreach ($this->groupJsonColumnsForUpdate($values) as $group) {
            foreach ($group as $key => $value) {
                $ordered[$key] = $value;
            }
        }

        return $this->prepareBindingsForUpdate($bindings, $ordered);
    }

    /**
     * Compile a delete query that does not use joins.
     */
    protected function compileDeleteWithoutJoins(Builder $query, string $table, string $where): string
    {
        $sql = parent::compileDeleteWithoutJoins($query, $table, $where);

        // When using MySQL, delete statements may contain order by statements and limits
        // so we will compile both of those here. Once we have finished compiling this
        // we will return the completed SQL statement so it will be executed for us.
        if (! empty($query->orders)) {
            $sql .= ' ' . $this->compileOrders($query, $query->orders);
        }

        if (isset($query->limit)) {
            $sql .= ' ' . $this->compileLimit($query, $query->limit);
        }

        return $sql;
    }

    /**
     * Compile a delete query that uses joins.
     *
     * Adds ORDER BY and LIMIT if present, for platforms that allow them (e.g., PlanetScale).
     * Standard MySQL does not support ORDER BY or LIMIT with joined deletes and will throw a syntax error.
     */
    protected function compileDeleteWithJoins(Builder $query, string $table, string $where): string
    {
        $sql = parent::compileDeleteWithJoins($query, $table, $where);

        if (! empty($query->orders)) {
            $sql .= ' ' . $this->compileOrders($query, $query->orders);
        }

        if (isset($query->limit)) {
            $sql .= ' ' . $this->compileLimit($query, $query->limit);
        }

        return $sql;
    }

    /**
     * Compile a query to get the number of open connections for a database.
     */
    public function compileThreadCount(): string
    {
        return 'select variable_value as `Value` from performance_schema.session_status where variable_name = \'threads_connected\'';
    }

    /**
     * Quote the given string literal.
     *
     * @param array<string>|string $value
     */
    #[Override]
    public function quoteString(string|array $value): string
    {
        // The parent quotes array members through this method.
        if (is_string($value) && $this->connection->usesBackslashEscapes()) {
            $value = str_replace('\\', '\\\\', $value);
        }

        return parent::quoteString($value);
    }

    /**
     * Wrap a single string in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        return $value === '*' ? $value : '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_unquote(json_extract(' . $field . $path . '))';
    }

    /**
     * Wrap the given JSON selector for boolean values.
     */
    protected function wrapJsonBooleanSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract(' . $field . $path . ')';
    }
}
