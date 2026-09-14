<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\IndexHint;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Override;

class SQLiteGrammar extends Grammar
{
    /**
     * All of the available clause operators.
     *
     * @var string[]
     */
    protected array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'ilike',
        '&', '|', '<<', '>>',
    ];

    /**
     * Compile the lock into SQL.
     */
    protected function compileLock(Builder $query, bool|string $value): string
    {
        return '';
    }

    /**
     * Wrap a union subquery in parentheses.
     */
    protected function wrapUnion(string $sql): string
    {
        return 'select * from (' . $sql . ')';
    }

    /**
     * Compile a "where like" clause.
     */
    protected function whereLike(Builder $query, array $where): string
    {
        if ($where['caseSensitive'] == false) {
            return parent::whereLike($query, $where);
        }
        $where['operator'] = $where['not'] ? 'not glob' : 'glob';

        return $this->whereBasic($query, $where);
    }

    /**
     * Convert a LIKE pattern to a GLOB pattern using simple string replacement.
     */
    public function prepareWhereLikeBinding(string $value, bool $caseSensitive): string
    {
        return $caseSensitive === false ? $value : str_replace(
            ['*', '?', '%', '_'],
            ['[*]', '[?]', '*', '?'],
            $value
        );
    }

    /**
     * Compile a "where null safe equals" clause.
     */
    protected function whereNullSafeEquals(Builder $query, array $where): string
    {
        $value = $this->parameter($where['value']);

        // SQLite's IS TRUE and IS FALSE test truthiness instead of equality.
        $value = match ($value) {
            'true' => '1',
            'false' => '0',
            default => $value,
        };

        return $this->wrap($where['column']) . ' is ' . $value;
    }

    /**
     * Compile a "where date" clause.
     */
    protected function whereDate(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%Y-%m-%d', $query, $where);
    }

    /**
     * Compile a "where day" clause.
     */
    protected function whereDay(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%d', $query, $where);
    }

    /**
     * Compile a "where month" clause.
     */
    protected function whereMonth(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%m', $query, $where);
    }

    /**
     * Compile a "where year" clause.
     */
    protected function whereYear(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%Y', $query, $where);
    }

    /**
     * Compile a "where time" clause.
     */
    protected function whereTime(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('%H:%M:%S', $query, $where);
    }

    /**
     * Compile a date based where clause.
     */
    protected function dateBasedWhere(string $type, Builder $query, array $where): string
    {
        $value = $this->parameter($where['value']);

        return "strftime('{$type}', {$this->wrap($where['column'])}) {$where['operator']} cast({$value} as text)";
    }

    /**
     * Compile the index hints for the query.
     *
     * @throws InvalidArgumentException
     */
    protected function compileIndexHint(Builder $query, IndexHint $indexHint): string
    {
        if ($indexHint->type !== 'force') {
            return '';
        }

        $index = $indexHint->index;

        if (! preg_match('/^[a-zA-Z0-9_$]+$/', $index)) {
            throw new InvalidArgumentException('Index name contains invalid characters.');
        }

        return "indexed by {$index}";
    }

    /**
     * Compile a "JSON length" statement into SQL.
     */
    protected function compileJsonLength(string $column, string $operator, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_array_length(' . $field . $path . ') ' . $operator . ' ' . $value;
    }

    /**
     * Compile a "JSON contains" statement into SQL.
     */
    protected function compileJsonContains(string $column, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'exists (select 1 from json_each(' . $field . $path . ') where ' . $this->wrap('json_each.value') . ' is ' . $value . ')';
    }

    /**
     * Prepare the binding for a "JSON contains" statement.
     */
    public function prepareBindingForJsonContains(mixed $binding): mixed
    {
        return $binding;
    }

    /**
     * Compile a "JSON contains key" statement into SQL.
     */
    protected function compileJsonContainsKey(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_type(' . $field . $path . ') is not null';
    }

    /**
     * Compile an update statement into SQL.
     */
    public function compileUpdate(Builder $query, array $values): string
    {
        if (isset($query->joins) || isset($query->limit)) {
            return $this->compileUpdateWithJoinsOrLimit($query, $values);
        }

        return parent::compileUpdate($query, $values);
    }

    /**
     * Compile an insert ignore statement into SQL.
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        return Str::replaceFirst('insert', 'insert or ignore', $this->compileInsert($query, $values));
    }

    /**
     * Compile an insert or ignore statement with a returning clause into SQL.
     */
    public function compileInsertOrIgnoreReturning(Builder $query, array $values, array $returning, ?array $uniqueBy): string
    {
        $insert = $this->compileInsert($query, $values);

        return match ($uniqueBy) {
            null => "{$insert} on conflict do nothing returning {$this->columnize($returning)}",
            default => "{$insert} on conflict ({$this->columnize($uniqueBy)}) do nothing returning {$this->columnize($returning)}",
        };
    }

    /**
     * Compile an insert ignore statement using a subquery into SQL.
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql): string
    {
        return Str::replaceFirst('insert', 'insert or ignore', $this->compileInsertUsing($query, $columns, $sql));
    }

    /**
     * Compile the columns for an update statement.
     */
    protected function compileUpdateColumns(Builder $query, array $values): string
    {
        return (new Collection($this->groupJsonColumnsForUpdate($values)))
            ->map(function (array $group, string $column): string {
                if ($this->isJsonSelector(array_key_first($group))) {
                    return $this->compileJsonUpdateColumn($column, $group);
                }

                return $this->wrap($column) . ' = ' . $this->parameter(reset($group));
            })
            ->implode(', ');
    }

    /**
     * Compile an "upsert" statement into SQL.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $sql = $this->compileInsert($query, $values);

        $sql .= ' on conflict (' . $this->columnize($uniqueBy) . ') do update set ';

        $columns = (new Collection($update))->map(function ($value, $key) {
            return is_numeric($key)
                ? $this->wrap($value) . ' = ' . $this->wrapValue('excluded') . '.' . $this->wrap($value)
                : $this->wrap($key) . ' = ' . $this->parameter($value);
        })->implode(', ');

        return $sql . $columns;
    }

    /**
     * Compile the JSON paths being updated on a column.
     */
    protected function compileJsonUpdateColumn(string $column, array $values): string
    {
        $field = $this->wrap($column);
        $value = "ifnull({$field}, json('{}'))";

        // SQLite before 3.48 permits 127 function arguments: one document and 63 path/value pairs.
        foreach (array_chunk($values, 63, true) as $group) {
            $paths = [];

            foreach ($group as $key => $pathValue) {
                $path = $this->wrapJsonPath(explode('->', $key, 2)[1]);
                $parameter = $this->isExpression($pathValue)
                    ? $this->getValue($pathValue)
                    : 'json(?)';

                $paths[] = ', ' . $path . ', ' . $parameter;
            }

            $value = 'json_set(' . $value . implode('', $paths) . ')';
        }

        return "{$field} = {$value}";
    }

    /**
     * Compile an update statement with joins or limit into SQL.
     */
    protected function compileUpdateWithJoinsOrLimit(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        $columns = $this->compileUpdateColumns($query, $values);

        $selectSql = $this->compileSelectQuery($query->select($this->qualifyRowIdentifier($query, 'rowid')));

        return "update {$table} set {$columns} where {$this->wrap('rowid')} in ({$selectSql})";
    }

    /**
     * Prepare the bindings for an update statement.
     */
    #[Override]
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        $cleanBindings = Arr::except($bindings, 'select');

        return array_values(
            array_merge($this->prepareValueBindingsForUpdate($values), Arr::flatten($cleanBindings))
        );
    }

    /**
     * Compile a delete statement into SQL.
     */
    public function compileDelete(Builder $query): string
    {
        if (isset($query->joins) || isset($query->limit)) {
            return $this->compileDeleteWithJoinsOrLimit($query);
        }

        return parent::compileDelete($query);
    }

    /**
     * Compile a delete statement with joins or limit into SQL.
     */
    protected function compileDeleteWithJoinsOrLimit(Builder $query): string
    {
        $table = $this->wrapTable($query->from);

        $selectSql = $this->compileSelectQuery($query->select($this->qualifyRowIdentifier($query, 'rowid')));

        return "delete from {$table} where {$this->wrap('rowid')} in ({$selectSql})";
    }

    /**
     * Compile a truncate table statement into SQL.
     */
    public function compileTruncate(Builder $query): array
    {
        [$schema, $table] = $query->getConnection()->getSchemaBuilder()->parseSchemaAndTable($query->from);

        $schema = $schema ? $this->wrapValue($schema) . '.' : '';

        return [
            'delete from ' . $schema . 'sqlite_sequence where name = ?' => [$query->getConnection()->getTablePrefix() . $table],
            'delete from ' . $this->wrapTable($query->from) => [],
        ];
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract(' . $field . $path . ')';
    }
}
