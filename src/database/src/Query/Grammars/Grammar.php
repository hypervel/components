<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hypervel\Database\Concerns\CompilesJsonPaths;
use Hypervel\Database\Contracts\Query\Expression;
use Hypervel\Database\Grammar as BaseGrammar;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Database\Query\JoinLateralClause;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use RuntimeException;

class Grammar extends BaseGrammar
{
    use CompilesJsonPaths;

    /**
     * The grammar specific operators.
     *
     * @var string[]
     */
    protected array $operators = [];

    /**
     * The grammar specific bitwise operators.
     *
     * @var string[]
     */
    protected array $bitwiseOperators = [];

    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected array $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'indexHint',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
    ];

    /**
     * Compile a select query into SQL.
     */
    public function compileSelect(Builder $query): string
    {
        if (($query->unions || $query->havings) && $query->aggregate) {
            return $this->compileUnionAggregate($query);
        }

        // If a "group limit" is in place, we will need to compile the SQL to use a
        // different syntax. This primarily supports limits on eager loads using
        // Eloquent. We'll also set the columns if they have not been defined.
        if (isset($query->groupLimit)) {
            if (is_null($query->columns)) {
                $query->columns = ['*'];
            }

            return $this->compileGroupLimit($query);
        }

        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the columns from the database. Then we
        // can build the query and concatenate all the pieces together as one.
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        // To compile the query, we'll spin through each component of the query and
        // see if that component exists. If it does we'll just call the compiler
        // function for the component which is responsible for making the SQL.
        $sql = trim($this->concatenate(
            $this->compileComponents($query))
        );

        if ($query->unions) {
            $sql = $this->wrapUnion($sql).' '.$this->compileUnions($query);
        }

        $query->columns = $original;

        return $sql;
    }

    /**
     * Compile the components necessary for a select clause.
     */
    protected function compileComponents(Builder $query): array
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            if (isset($query->$component)) {
                $method = 'compile'.ucfirst($component);

                $sql[$component] = $this->$method($query, $query->$component);
            }
        }

        return $sql;
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param  array{function: string, columns: array<Expression|string>}  $aggregate
     */
    protected function compileAggregate(Builder $query, array $aggregate): string
    {
        $column = $this->columnize($aggregate['columns']);

        // If the query has a "distinct" constraint and we're not asking for all columns
        // we need to prepend "distinct" onto the column name so that the query takes
        // it into account when it performs the aggregating operations on the data.
        if (is_array($query->distinct)) {
            $column = 'distinct '.$this->columnize($query->distinct);
        } elseif ($query->distinct && $column !== '*') {
            $column = 'distinct '.$column;
        }

        return 'select '.$aggregate['function'].'('.$column.') as aggregate';
    }

    /**
     * Compile the "select *" portion of the query.
     */
    protected function compileColumns(Builder $query, array $columns): ?string
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        if (! is_null($query->aggregate)) {
            return null;
        }

        if ($query->distinct) {
            $select = 'select distinct ';
        } else {
            $select = 'select ';
        }

        return $select.$this->columnize($columns);
    }

    /**
     * Compile the "from" portion of the query.
     */
    protected function compileFrom(Builder $query, string $table): string
    {
        return 'from '.$this->wrapTable($table);
    }

    /**
     * Compile the "join" portions of the query.
     */
    protected function compileJoins(Builder $query, array $joins): string
    {
        return (new Collection($joins))->map(function ($join) use ($query) {
            $table = $this->wrapTable($join->table);

            $nestedJoins = is_null($join->joins) ? '' : ' '.$this->compileJoins($query, $join->joins);

            $tableAndNestedJoins = is_null($join->joins) ? $table : '('.$table.$nestedJoins.')';

            if ($join instanceof JoinLateralClause) {
                return $this->compileJoinLateral($join, $tableAndNestedJoins);
            }

            return trim("{$join->type} join {$tableAndNestedJoins} {$this->compileWheres($join)}");
        })->implode(' ');
    }

    /**
     * Compile a "lateral join" clause.
     *
     * @throws RuntimeException
     */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        throw new RuntimeException('This database engine does not support lateral joins.');
    }

    /**
     * Compile the "where" portions of the query.
     */
    public function compileWheres(Builder $query): string
    {
        // Each type of where clause has its own compiler function, which is responsible
        // for actually creating the where clauses SQL. This helps keep the code nice
        // and maintainable since each clause has a very small method that it uses.
        if (! $query->wheres) {
            return '';
        }

        // If we actually have some where clauses, we will strip off the first boolean
        // operator, which is added by the query builders for convenience so we can
        // avoid checking for the first clauses in each of the compilers methods.
        return $this->concatenateWhereClauses($query, $this->compileWheresToArray($query));
    }

    /**
     * Get an array of all the where clauses for the query.
     */
    protected function compileWheresToArray(Builder $query): array
    {
        return (new Collection($query->wheres))
            ->map(fn ($where) => $where['boolean'].' '.$this->{"where{$where['type']}"}($query, $where))
            ->all();
    }

    /**
     * Format the where clause statements into one string.
     */
    protected function concatenateWhereClauses(Builder $query, array $sql): string
    {
        $conjunction = $query instanceof JoinClause ? 'on' : 'where';

        return $conjunction.' '.$this->removeLeadingBoolean(implode(' ', $sql));
    }

    /**
     * Compile a raw where clause.
     */
    protected function whereRaw(Builder $query, array $where): string
    {
        return $where['sql'] instanceof Expression ? $where['sql']->getValue($this) : $where['sql'];
    }

    /**
     * Compile a basic where clause.
     */
    protected function whereBasic(Builder $query, array $where): string
    {
        $value = $this->parameter($where['value']);

        $operator = str_replace('?', '??', $where['operator']);

        return $this->wrap($where['column']).' '.$operator.' '.$value;
    }

    /**
     * Compile a bitwise operator where clause.
     */
    protected function whereBitwise(Builder $query, array $where): string
    {
        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a "where like" clause.
     */
    protected function whereLike(Builder $query, array $where): string
    {
        if ($where['caseSensitive']) {
            throw new RuntimeException('This database engine does not support case sensitive like operations.');
        }

        $where['operator'] = $where['not'] ? 'not like' : 'like';

        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a "where in" clause.
     */
    protected function whereIn(Builder $query, array $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' in ('.$this->parameterize($where['values']).')';
        }

        return '0 = 1';
    }

    /**
     * Compile a "where not in" clause.
     */
    protected function whereNotIn(Builder $query, array $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' not in ('.$this->parameterize($where['values']).')';
        }

        return '1 = 1';
    }

    /**
     * Compile a "where not in raw" clause.
     *
     * For safety, whereIntegerInRaw ensures this method is only used with integer values.
     */
    protected function whereNotInRaw(Builder $query, array $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' not in ('.implode(', ', $where['values']).')';
        }

        return '1 = 1';
    }

    /**
     * Compile a "where in raw" clause.
     *
     * For safety, whereIntegerInRaw ensures this method is only used with integer values.
     */
    protected function whereInRaw(Builder $query, array $where): string
    {
        if (! empty($where['values'])) {
            return $this->wrap($where['column']).' in ('.implode(', ', $where['values']).')';
        }

        return '0 = 1';
    }

    /**
     * Compile a "where null" clause.
     */
    protected function whereNull(Builder $query, array $where): string
    {
        return $this->wrap($where['column']).' is null';
    }

    /**
     * Compile a "where not null" clause.
     */
    protected function whereNotNull(Builder $query, array $where): string
    {
        return $this->wrap($where['column']).' is not null';
    }

    /**
     * Compile a "between" where clause.
     */
    protected function whereBetween(Builder $query, array $where): string
    {
        $between = $where['not'] ? 'not between' : 'between';

        $min = $this->parameter(is_array($where['values']) ? Arr::first($where['values']) : $where['values'][0]);

        $max = $this->parameter(is_array($where['values']) ? Arr::last($where['values']) : $where['values'][1]);

        return $this->wrap($where['column']).' '.$between.' '.$min.' and '.$max;
    }

    /**
     * Compile a "between" where clause.
     */
    protected function whereBetweenColumns(Builder $query, array $where): string
    {
        $between = $where['not'] ? 'not between' : 'between';

        $min = $this->wrap(is_array($where['values']) ? Arr::first($where['values']) : $where['values'][0]);

        $max = $this->wrap(is_array($where['values']) ? Arr::last($where['values']) : $where['values'][1]);

        return $this->wrap($where['column']).' '.$between.' '.$min.' and '.$max;
    }

    /**
     * Compile a "value between" where clause.
     */
    protected function whereValueBetween(Builder $query, array $where): string
    {
        $between = $where['not'] ? 'not between' : 'between';

        $min = $this->wrap(is_array($where['columns']) ? Arr::first($where['columns']) : $where['columns'][0]);

        $max = $this->wrap(is_array($where['columns']) ? Arr::last($where['columns']) : $where['columns'][1]);

        return $this->parameter($where['value']).' '.$between.' '.$min.' and '.$max;
    }

    /**
     * Compile a "where date" clause.
     */
    protected function whereDate(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('date', $query, $where);
    }

    /**
     * Compile a "where time" clause.
     */
    protected function whereTime(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('time', $query, $where);
    }

    /**
     * Compile a "where day" clause.
     */
    protected function whereDay(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('day', $query, $where);
    }

    /**
     * Compile a "where month" clause.
     */
    protected function whereMonth(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('month', $query, $where);
    }

    /**
     * Compile a "where year" clause.
     */
    protected function whereYear(Builder $query, array $where): string
    {
        return $this->dateBasedWhere('year', $query, $where);
    }

    /**
     * Compile a date based where clause.
     */
    protected function dateBasedWhere(string $type, Builder $query, array $where): string
    {
        $value = $this->parameter($where['value']);

        return $type.'('.$this->wrap($where['column']).') '.$where['operator'].' '.$value;
    }

    /**
     * Compile a where clause comparing two columns.
     */
    protected function whereColumn(Builder $query, array $where): string
    {
        return $this->wrap($where['first']).' '.$where['operator'].' '.$this->wrap($where['second']);
    }

    /**
     * Compile a nested where clause.
     */
    protected function whereNested(Builder $query, array $where): string
    {
        // Here we will calculate what portion of the string we need to remove. If this
        // is a join clause query, we need to remove the "on" portion of the SQL and
        // if it is a normal query we need to take the leading "where" of queries.
        $offset = $where['query'] instanceof JoinClause ? 3 : 6;

        return '('.substr($this->compileWheres($where['query']), $offset).')';
    }

    /**
     * Compile a where condition with a sub-select.
     */
    protected function whereSub(Builder $query, array $where): string
    {
        $select = $this->compileSelect($where['query']);

        return $this->wrap($where['column']).' '.$where['operator']." ($select)";
    }

    /**
     * Compile a where exists clause.
     */
    protected function whereExists(Builder $query, array $where): string
    {
        return 'exists ('.$this->compileSelect($where['query']).')';
    }

    /**
     * Compile a where not exists clause.
     */
    protected function whereNotExists(Builder $query, array $where): string
    {
        return 'not exists ('.$this->compileSelect($where['query']).')';
    }

    /**
     * Compile a where row values condition.
     */
    protected function whereRowValues(Builder $query, array $where): string
    {
        $columns = $this->columnize($where['columns']);

        $values = $this->parameterize($where['values']);

        return '('.$columns.') '.$where['operator'].' ('.$values.')';
    }

    /**
     * Compile a "where JSON boolean" clause.
     */
    protected function whereJsonBoolean(Builder $query, array $where): string
    {
        $column = $this->wrapJsonBooleanSelector($where['column']);

        $value = $this->wrapJsonBooleanValue(
            $this->parameter($where['value'])
        );

        return $column.' '.$where['operator'].' '.$value;
    }

    /**
     * Compile a "where JSON contains" clause.
     */
    protected function whereJsonContains(Builder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $not.$this->compileJsonContains(
            $where['column'],
            $this->parameter($where['value'])
        );
    }

    /**
     * Compile a "JSON contains" statement into SQL.
     *
     * @throws RuntimeException
     */
    protected function compileJsonContains(string $column, string $value): string
    {
        throw new RuntimeException('This database engine does not support JSON contains operations.');
    }

    /**
     * Compile a "where JSON overlaps" clause.
     */
    protected function whereJsonOverlaps(Builder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $not.$this->compileJsonOverlaps(
            $where['column'],
            $this->parameter($where['value'])
        );
    }

    /**
     * Compile a "JSON overlaps" statement into SQL.
     *
     * @throws RuntimeException
     */
    protected function compileJsonOverlaps(string $column, string $value): string
    {
        throw new RuntimeException('This database engine does not support JSON overlaps operations.');
    }

    /**
     * Prepare the binding for a "JSON contains" statement.
     */
    public function prepareBindingForJsonContains(mixed $binding): string
    {
        return json_encode($binding, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Compile a "where JSON contains key" clause.
     */
    protected function whereJsonContainsKey(Builder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $not.$this->compileJsonContainsKey(
            $where['column']
        );
    }

    /**
     * Compile a "JSON contains key" statement into SQL.
     *
     * @throws RuntimeException
     */
    protected function compileJsonContainsKey(string $column): string
    {
        throw new RuntimeException('This database engine does not support JSON contains key operations.');
    }

    /**
     * Compile a "where JSON length" clause.
     */
    protected function whereJsonLength(Builder $query, array $where): string
    {
        return $this->compileJsonLength(
            $where['column'],
            $where['operator'],
            $this->parameter($where['value'])
        );
    }

    /**
     * Compile a "JSON length" statement into SQL.
     *
     * @throws RuntimeException
     */
    protected function compileJsonLength(string $column, string $operator, string $value): string
    {
        throw new RuntimeException('This database engine does not support JSON length operations.');
    }

    /**
     * Compile a "JSON value cast" statement into SQL.
     */
    public function compileJsonValueCast(string $value): string
    {
        return $value;
    }

    /**
     * Compile a "where fulltext" clause.
     */
    public function whereFullText(Builder $query, array $where): string
    {
        throw new RuntimeException('This database engine does not support fulltext search operations.');
    }

    /**
     * Compile a clause based on an expression.
     */
    public function whereExpression(Builder $query, array $where): string
    {
        return $where['column']->getValue($this);
    }

    /**
     * Compile the "group by" portions of the query.
     */
    protected function compileGroups(Builder $query, array $groups): string
    {
        return 'group by '.$this->columnize($groups);
    }

    /**
     * Compile the "having" portions of the query.
     */
    protected function compileHavings(Builder $query): string
    {
        return 'having '.$this->removeLeadingBoolean((new Collection($query->havings))->map(function ($having) {
            return $having['boolean'].' '.$this->compileHaving($having);
        })->implode(' '));
    }

    /**
     * Compile a single having clause.
     */
    protected function compileHaving(array $having): string
    {
        // If the having clause is "raw", we can just return the clause straight away
        // without doing any more processing on it. Otherwise, we will compile the
        // clause into SQL based on the components that make it up from builder.
        return match ($having['type']) {
            'Raw' => $having['sql'],
            'between' => $this->compileHavingBetween($having),
            'Null' => $this->compileHavingNull($having),
            'NotNull' => $this->compileHavingNotNull($having),
            'bit' => $this->compileHavingBit($having),
            'Expression' => $this->compileHavingExpression($having),
            'Nested' => $this->compileNestedHavings($having),
            default => $this->compileBasicHaving($having),
        };
    }

    /**
     * Compile a basic having clause.
     */
    protected function compileBasicHaving(array $having): string
    {
        $column = $this->wrap($having['column']);

        $parameter = $this->parameter($having['value']);

        return $column.' '.$having['operator'].' '.$parameter;
    }

    /**
     * Compile a "between" having clause.
     */
    protected function compileHavingBetween(array $having): string
    {
        $between = $having['not'] ? 'not between' : 'between';

        $column = $this->wrap($having['column']);

        $min = $this->parameter(head($having['values']));

        $max = $this->parameter(last($having['values']));

        return $column.' '.$between.' '.$min.' and '.$max;
    }

    /**
     * Compile a having null clause.
     */
    protected function compileHavingNull(array $having): string
    {
        $column = $this->wrap($having['column']);

        return $column.' is null';
    }

    /**
     * Compile a having not null clause.
     */
    protected function compileHavingNotNull(array $having): string
    {
        $column = $this->wrap($having['column']);

        return $column.' is not null';
    }

    /**
     * Compile a having clause involving a bit operator.
     */
    protected function compileHavingBit(array $having): string
    {
        $column = $this->wrap($having['column']);

        $parameter = $this->parameter($having['value']);

        return '('.$column.' '.$having['operator'].' '.$parameter.') != 0';
    }

    /**
     * Compile a having clause involving an expression.
     */
    protected function compileHavingExpression(array $having): string
    {
        return $having['column']->getValue($this);
    }

    /**
     * Compile a nested having clause.
     */
    protected function compileNestedHavings(array $having): string
    {
        return '('.substr($this->compileHavings($having['query']), 7).')';
    }

    /**
     * Compile the "order by" portions of the query.
     */
    protected function compileOrders(Builder $query, array $orders): string
    {
        if (! empty($orders)) {
            return 'order by '.implode(', ', $this->compileOrdersToArray($query, $orders));
        }

        return '';
    }

    /**
     * Compile the query orders to an array.
     */
    protected function compileOrdersToArray(Builder $query, array $orders): array
    {
        return array_map(function ($order) use ($query) {
            if (isset($order['sql']) && $order['sql'] instanceof Expression) {
                return $order['sql']->getValue($query->getGrammar());
            }

            return $order['sql'] ?? $this->wrap($order['column']).' '.$order['direction'];
        }, $orders);
    }

    /**
     * Compile the random statement into SQL.
     */
    public function compileRandom(string|int $seed): string
    {
        return 'RANDOM()';
    }

    /**
     * Compile the "limit" portions of the query.
     */
    protected function compileLimit(Builder $query, int $limit): string
    {
        return 'limit '.(int) $limit;
    }

    /**
     * Compile a group limit clause.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        $selectBindings = array_merge($query->getRawBindings()['select'], $query->getRawBindings()['order']);

        $query->setBindings($selectBindings, 'select');
        $query->setBindings([], 'order');

        $limit = (int) $query->groupLimit['value'];
        $offset = $query->offset;

        if (isset($offset)) {
            $offset = (int) $offset;
            $limit += $offset;

            $query->offset = null;
        }

        $components = $this->compileComponents($query);

        $components['columns'] .= $this->compileRowNumber(
            $query->groupLimit['column'],
            $components['orders'] ?? ''
        );

        unset($components['orders']);

        $table = $this->wrap('laravel_table');
        $row = $this->wrap('laravel_row');

        $sql = $this->concatenate($components);

        $sql = 'select * from ('.$sql.') as '.$table.' where '.$row.' <= '.$limit;

        if (isset($offset)) {
            $sql .= ' and '.$row.' > '.$offset;
        }

        return $sql.' order by '.$row;
    }

    /**
     * Compile a row number clause.
     */
    protected function compileRowNumber(string $partition, string $orders): string
    {
        $over = trim('partition by '.$this->wrap($partition).' '.$orders);

        return ', row_number() over ('.$over.') as '.$this->wrap('laravel_row');
    }

    /**
     * Compile the "offset" portions of the query.
     */
    protected function compileOffset(Builder $query, int $offset): string
    {
        return 'offset '.(int) $offset;
    }

    /**
     * Compile the "union" queries attached to the main query.
     */
    protected function compileUnions(Builder $query): string
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' '.$this->compileOrders($query, $query->unionOrders);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        return ltrim($sql);
    }

    /**
     * Compile a single union statement.
     */
    protected function compileUnion(array $union): string
    {
        $conjunction = $union['all'] ? ' union all ' : ' union ';

        return $conjunction.$this->wrapUnion($union['query']->toSql());
    }

    /**
     * Wrap a union subquery in parentheses.
     */
    protected function wrapUnion(string $sql): string
    {
        return '('.$sql.')';
    }

    /**
     * Compile a union aggregate query into SQL.
     */
    protected function compileUnionAggregate(Builder $query): string
    {
        $sql = $this->compileAggregate($query, $query->aggregate);

        $query->aggregate = null;

        return $sql.' from ('.$this->compileSelect($query).') as '.$this->wrapTable('temp_table');
    }

    /**
     * Compile an exists statement into SQL.
     */
    public function compileExists(Builder $query): string
    {
        $select = $this->compileSelect($query);

        return "select exists({$select}) as {$this->wrap('exists')}";
    }

    /**
     * Compile an insert statement into SQL.
     */
    public function compileInsert(Builder $query, array $values): string
    {
        // Essentially we will force every insert to be treated as a batch insert which
        // simply makes creating the SQL easier for us since we can utilize the same
        // basic routine regardless of an amount of records given to us to insert.
        $table = $this->wrapTable($query->from);

        if (empty($values)) {
            return "insert into {$table} default values";
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        }

        $columns = $this->columnize(array_keys(Arr::first($values)));

        // We need to build a list of parameter place-holders of values that are bound
        // to the query. Each insert should have the exact same number of parameter
        // bindings so we will loop through the record and parameterize them all.
        $parameters = (new Collection($values))
            ->map(fn ($record) => '('.$this->parameterize($record).')')
            ->implode(', ');

        return "insert into $table ($columns) values $parameters";
    }

    /**
     * Compile an insert ignore statement into SQL.
     *
     * @throws RuntimeException
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        throw new RuntimeException('This database engine does not support inserting while ignoring errors.');
    }

    /**
     * Compile an insert and get ID statement into SQL.
     */
    public function compileInsertGetId(Builder $query, array $values, ?string $sequence): string
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * Compile an insert statement using a subquery into SQL.
     */
    public function compileInsertUsing(Builder $query, array $columns, string $sql): string
    {
        $table = $this->wrapTable($query->from);

        if (empty($columns) || $columns === ['*']) {
            return "insert into {$table} $sql";
        }

        return "insert into {$table} ({$this->columnize($columns)}) $sql";
    }

    /**
     * Compile an insert ignore statement using a subquery into SQL.
     *
     * @throws RuntimeException
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql): string
    {
        throw new RuntimeException('This database engine does not support inserting while ignoring errors.');
    }

    /**
     * Compile an update statement into SQL.
     */
    public function compileUpdate(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        $columns = $this->compileUpdateColumns($query, $values);

        $where = $this->compileWheres($query);

        return trim(
            isset($query->joins)
                ? $this->compileUpdateWithJoins($query, $table, $columns, $where)
                : $this->compileUpdateWithoutJoins($query, $table, $columns, $where)
        );
    }

    /**
     * Compile the columns for an update statement.
     */
    protected function compileUpdateColumns(Builder $query, array $values): string
    {
        return (new Collection($values))
            ->map(fn ($value, $key) => $this->wrap($key).' = '.$this->parameter($value))
            ->implode(', ');
    }

    /**
     * Compile an update statement without joins into SQL.
     */
    protected function compileUpdateWithoutJoins(Builder $query, string $table, string $columns, string $where): string
    {
        return "update {$table} set {$columns} {$where}";
    }

    /**
     * Compile an update statement with joins into SQL.
     */
    protected function compileUpdateWithJoins(Builder $query, string $table, string $columns, string $where): string
    {
        $joins = $this->compileJoins($query, $query->joins);

        return "update {$table} {$joins} set {$columns} {$where}";
    }

    /**
     * Compile an "upsert" statement into SQL.
     *
     * @throws RuntimeException
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        throw new RuntimeException('This database engine does not support upserts.');
    }

    /**
     * Prepare the bindings for an update statement.
     */
    public function prepareBindingsForUpdate(array $bindings, array $values): array
    {
        $cleanBindings = Arr::except($bindings, ['select', 'join']);

        $values = Arr::flatten(array_map(fn ($value) => value($value), $values));

        return array_values(
            array_merge($bindings['join'], $values, Arr::flatten($cleanBindings))
        );
    }

    /**
     * Compile a delete statement into SQL.
     */
    public function compileDelete(Builder $query): string
    {
        $table = $this->wrapTable($query->from);

        $where = $this->compileWheres($query);

        return trim(
            isset($query->joins)
                ? $this->compileDeleteWithJoins($query, $table, $where)
                : $this->compileDeleteWithoutJoins($query, $table, $where)
        );
    }

    /**
     * Compile a delete statement without joins into SQL.
     */
    protected function compileDeleteWithoutJoins(Builder $query, string $table, string $where): string
    {
        return "delete from {$table} {$where}";
    }

    /**
     * Compile a delete statement with joins into SQL.
     */
    protected function compileDeleteWithJoins(Builder $query, string $table, string $where): string
    {
        $alias = last(explode(' as ', $table));

        $joins = $this->compileJoins($query, $query->joins);

        return "delete {$alias} from {$table} {$joins} {$where}";
    }

    /**
     * Prepare the bindings for a delete statement.
     */
    public function prepareBindingsForDelete(array $bindings): array
    {
        return Arr::flatten(
            Arr::except($bindings, 'select')
        );
    }

    /**
     * Compile a truncate table statement into SQL.
     */
    public function compileTruncate(Builder $query): array
    {
        return ['truncate table '.$this->wrapTable($query->from) => []];
    }

    /**
     * Compile the lock into SQL.
     */
    protected function compileLock(Builder $query, bool|string $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Compile a query to get the number of open connections for a database.
     */
    public function compileThreadCount(): ?string
    {
        return null;
    }

    /**
     * Determine if the grammar supports savepoints.
     */
    public function supportsSavepoints(): bool
    {
        return true;
    }

    /**
     * Compile the SQL statement to define a savepoint.
     */
    public function compileSavepoint(string $name): string
    {
        return 'SAVEPOINT '.$name;
    }

    /**
     * Compile the SQL statement to execute a savepoint rollback.
     */
    public function compileSavepointRollBack(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT '.$name;
    }

    /**
     * Wrap the given JSON selector for boolean values.
     */
    protected function wrapJsonBooleanSelector(string $value): string
    {
        return $this->wrapJsonSelector($value);
    }

    /**
     * Wrap the given JSON boolean value.
     */
    protected function wrapJsonBooleanValue(string $value): string
    {
        return $value;
    }

    /**
     * Concatenate an array of segments, removing empties.
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Remove the leading boolean from a statement.
     */
    protected function removeLeadingBoolean(string $value): string
    {
        return preg_replace('/and |or /i', '', $value, 1);
    }

    /**
     * Substitute the given bindings into the given raw SQL query.
     */
    public function substituteBindingsIntoRawSql(string $sql, array $bindings): string
    {
        $bindings = array_map(fn ($value) => $this->escape($value, is_resource($value) || gettype($value) === 'resource (closed)'), $bindings);

        $query = '';

        $isStringLiteral = false;

        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            $nextChar = $sql[$i + 1] ?? null;

            // Single quotes can be escaped as '' according to the SQL standard while
            // MySQL uses \'. Postgres has operators like ?| that must get encoded
            // in PHP like ??|. We should skip over the escaped characters here.
            if (in_array($char.$nextChar, ["\'", "''", '??'])) {
                $query .= $char.$nextChar;
                $i += 1;
            } elseif ($char === "'") { // Starting / leaving string literal...
                $query .= $char;
                $isStringLiteral = ! $isStringLiteral;
            } elseif ($char === '?' && ! $isStringLiteral) { // Substitutable binding...
                $query .= array_shift($bindings) ?? '?';
            } else { // Normal character...
                $query .= $char;
            }
        }

        return $query;
    }

    /**
     * Get the grammar specific operators.
     *
     * @return string[]
     */
    public function getOperators(): array
    {
        return $this->operators;
    }

    /**
     * Get the grammar specific bitwise operators.
     *
     * @return string[]
     */
    public function getBitwiseOperators(): array
    {
        return $this->bitwiseOperators;
    }
}
