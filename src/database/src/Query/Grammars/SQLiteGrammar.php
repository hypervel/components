<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\IndexHint;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

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
        return 'select * from ('.$sql.')';
    }

    /**
     * Compile a basic where clause.
     */
    protected function whereBasic(Builder $query, array $where): string
    {
        if ($where['operator'] === '<=>') {
            $column = $this->wrap($where['column']);
            $value = $this->parameter($where['value']);

            return "{$column} IS {$value}";
        }

        return parent::whereBasic($query, $where);
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
     */
    protected function compileIndexHint(Builder $query, IndexHint $indexHint): string
    {
        return $indexHint->type === 'force'
            ? "indexed by {$indexHint->index}"
            : '';
    }

    /**
     * Compile a "JSON length" statement into SQL.
     */
    protected function compileJsonLength(string $column, string $operator, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_array_length('.$field.$path.') '.$operator.' '.$value;
    }

    /**
     * Compile a "JSON contains" statement into SQL.
     */
    protected function compileJsonContains(string $column, string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'exists (select 1 from json_each('.$field.$path.') where '.$this->wrap('json_each.value').' is '.$value.')';
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

        return 'json_type('.$field.$path.') is not null';
    }

    /**
     * Compile a group limit clause.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        $version = $query->getConnection()->getServerVersion();

        if (version_compare($version, '3.25.0', '>=')) {
            return parent::compileGroupLimit($query);
        }

        $query->groupLimit = null;

        return $this->compileSelect($query);
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
        $jsonGroups = $this->groupJsonColumnsForUpdate($values);

        return (new Collection($values))
            ->reject(fn ($value, $key) => $this->isJsonSelector($key))
            ->merge($jsonGroups)
            ->map(function ($value, $key) use ($jsonGroups) {
                $column = last(explode('.', $key));

                $value = isset($jsonGroups[$key]) ? $this->compileJsonPatch($column, $value) : $this->parameter($value);

                return $this->wrap($column).' = '.$value;
            })
            ->implode(', ');
    }

    /**
     * Compile an "upsert" statement into SQL.
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $sql = $this->compileInsert($query, $values);

        $sql .= ' on conflict ('.$this->columnize($uniqueBy).') do update set ';

        $columns = (new Collection($update))->map(function ($value, $key) {
            return is_numeric($key)
                ? $this->wrap($value).' = '.$this->wrapValue('excluded').'.'.$this->wrap($value)
                : $this->wrap($key).' = '.$this->parameter($value);
        })->implode(', ');

        return $sql.$columns;
    }

    /**
     * Group the nested JSON columns.
     */
    protected function groupJsonColumnsForUpdate(array $values): array
    {
        $groups = [];

        foreach ($values as $key => $value) {
            if ($this->isJsonSelector($key)) {
                Arr::set($groups, str_replace('->', '.', Str::after($key, '.')), $value);
            }
        }

        return $groups;
    }

    /**
     * Compile a "JSON" patch statement into SQL.
     */
    protected function compileJsonPatch(string $column, mixed $value): string
    {
        return "json_patch(ifnull({$this->wrap($column)}, json('{}')), json({$this->parameter($value)}))";
    }

    /**
     * Compile an update statement with joins or limit into SQL.
     */
    protected function compileUpdateWithJoinsOrLimit(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        $columns = $this->compileUpdateColumns($query, $values);

        $alias = last(preg_split('/\s+as\s+/i', $query->from));

        $selectSql = $this->compileSelect($query->select($alias.'.rowid'));

        return "update {$table} set {$columns} where {$this->wrap('rowid')} in ({$selectSql})";
    }

    /**
     * Prepare the bindings for an update statement.
     */
    #[\Override]
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        $groups = $this->groupJsonColumnsForUpdate($values);

        $values = (new Collection($values))
            ->reject(fn ($value, $key) => $this->isJsonSelector($key))
            ->merge($groups)
            ->map(fn ($value) => is_array($value) ? json_encode($value) : $value)
            ->all();

        $cleanBindings = Arr::except($bindings, 'select');

        $values = Arr::flatten(array_map(fn ($value) => value($value), $values));

        return array_values(
            array_merge($values, Arr::flatten($cleanBindings))
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

        $alias = last(preg_split('/\s+as\s+/i', $query->from));

        $selectSql = $this->compileSelect($query->select($alias.'.rowid'));

        return "delete from {$table} where {$this->wrap('rowid')} in ({$selectSql})";
    }

    /**
     * Compile a truncate table statement into SQL.
     */
    public function compileTruncate(Builder $query): array
    {
        [$schema, $table] = $query->getConnection()->getSchemaBuilder()->parseSchemaAndTable($query->from);

        $schema = $schema ? $this->wrapValue($schema).'.' : '';

        return [
            'delete from '.$schema.'sqlite_sequence where name = ?' => [$query->getConnection()->getTablePrefix().$table],
            'delete from '.$this->wrapTable($query->from) => [],
        ];
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_extract('.$field.$path.')';
    }
}
