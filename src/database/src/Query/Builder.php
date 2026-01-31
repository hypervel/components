<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

use BackedEnum;
use BadMethodCallException;
use Carbon\CarbonPeriod;
use Closure;
use DateTimeInterface;
use Hypervel\Contracts\Database\Query\Builder as BuilderContract;
use Hypervel\Contracts\Database\Query\ConditionExpression;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Concerns\BuildsQueries;
use Hypervel\Database\Concerns\BuildsWhereDateClauses;
use Hypervel\Database\Concerns\ExplainsQueries;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Str;
use Hypervel\Support\StrCache;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Builder implements BuilderContract
{
    /** @use \Hypervel\Database\Concerns\BuildsQueries<\stdClass> */
    use BuildsWhereDateClauses, BuildsQueries, ExplainsQueries, ForwardsCalls, Macroable {
        __call as macroCall;
    }

    /**
     * The database connection instance.
     */
    public ConnectionInterface $connection;

    /**
     * The database query grammar instance.
     */
    public Grammar $grammar;

    /**
     * The database query post processor instance.
     */
    public Processor $processor;

    /**
     * The current query value bindings.
     *
     * @var array{
     *     select: list<mixed>,
     *     from: list<mixed>,
     *     join: list<mixed>,
     *     where: list<mixed>,
     *     groupBy: list<mixed>,
     *     having: list<mixed>,
     *     order: list<mixed>,
     *     union: list<mixed>,
     *     unionOrder: list<mixed>,
     * }
     */
    public array $bindings = [
        'select' => [],
        'from' => [],
        'join' => [],
        'where' => [],
        'groupBy' => [],
        'having' => [],
        'order' => [],
        'union' => [],
        'unionOrder' => [],
    ];

    /**
     * An aggregate function and column to be run.
     *
     * @var null|array{
     *     function: string,
     *     columns: array<\Hypervel\Contracts\Database\Query\Expression|string>
     * }
     */
    public ?array $aggregate = null;

    /**
     * The columns that should be returned.
     *
     * @var null|array<\Hypervel\Contracts\Database\Query\Expression|string>
     */
    public ?array $columns = null;

    /**
     * Indicates if the query returns distinct results.
     *
     * Occasionally contains the columns that should be distinct.
     */
    public bool|array $distinct = false;

    /**
     * The table which the query is targeting.
     */
    public Expression|string|null $from = null;

    /**
     * The index hint for the query.
     */
    public ?IndexHint $indexHint = null;

    /**
     * The table joins for the query.
     */
    public ?array $joins = null;

    /**
     * The where constraints for the query.
     */
    public array $wheres = [];

    /**
     * The groupings for the query.
     */
    public ?array $groups = null;

    /**
     * The having constraints for the query.
     */
    public ?array $havings = null;

    /**
     * The orderings for the query.
     */
    public ?array $orders = null;

    /**
     * The maximum number of records to return.
     */
    public ?int $limit = null;

    /**
     * The maximum number of records to return per group.
     */
    public ?array $groupLimit = null;

    /**
     * The number of records to skip.
     */
    public ?int $offset = null;

    /**
     * The query union statements.
     */
    public ?array $unions = null;

    /**
     * The maximum number of union records to return.
     */
    public ?int $unionLimit = null;

    /**
     * The number of union records to skip.
     */
    public ?int $unionOffset = null;

    /**
     * The orderings for the union query.
     */
    public ?array $unionOrders = null;

    /**
     * Indicates whether row locking is being used.
     */
    public string|bool|null $lock = null;

    /**
     * The callbacks that should be invoked before the query is executed.
     */
    public array $beforeQueryCallbacks = [];

    /**
     * The callbacks that should be invoked after retrieving data from the database.
     */
    protected array $afterQueryCallbacks = [];

    /**
     * All of the available clause operators.
     *
     * @var string[]
     */
    public array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>', '&~', 'is', 'is not',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    /**
     * All of the available bitwise operators.
     *
     * @var string[]
     */
    public array $bitwiseOperators = [
        '&', '|', '^', '<<', '>>', '&~',
    ];

    /**
     * Whether to use write pdo for the select.
     */
    public bool $useWritePdo = false;

    /**
     * The PDO fetch mode arguments for the query.
     */
    public array $fetchUsing = [];

    /**
     * Create a new query builder instance.
     */
    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
    ) {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
        $this->processor = $processor ?: $connection->getPostProcessor();
    }

    /**
     * Set the columns to be selected.
     */
    public function select(mixed $columns = ['*']): static
    {
        $this->columns = [];
        $this->bindings['select'] = [];

        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $as => $column) {
            if (is_string($as) && $this->isQueryable($column)) {
                $this->selectSub($column, $as);
            } else {
                $this->columns[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     *
     * @throws InvalidArgumentException
     */
    public function selectSub(Closure|self|EloquentBuilder|string $query, string $as): static
    {
        [$query, $bindings] = $this->createSub($query);

        return $this->selectRaw(
            '(' . $query . ') as ' . $this->grammar->wrap($as),
            $bindings
        );
    }

    /**
     * Add an expression to the select clause.
     */
    public function selectExpression(ExpressionContract $expression, string $as): static
    {
        return $this->selectRaw(
            '(' . $expression->getValue($this->grammar) . ') as ' . $this->grammar->wrap($as)
        );
    }

    /**
     * Add a new "raw" select expression to the query.
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->addSelect(new Expression($expression));

        if ($bindings) {
            $this->addBinding($bindings, 'select');
        }

        return $this;
    }

    /**
     * Makes "from" fetch from a subquery.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     *
     * @throws InvalidArgumentException
     */
    public function fromSub(Closure|self|EloquentBuilder|string $query, string $as): static
    {
        [$query, $bindings] = $this->createSub($query);

        return $this->fromRaw('(' . $query . ') as ' . $this->grammar->wrapTable($as), $bindings);
    }

    /**
     * Add a raw "from" clause to the query.
     */
    public function fromRaw(Expression|string $expression, mixed $bindings = []): static
    {
        $this->from = $expression instanceof Expression
            ? $expression
            : new Expression($expression);

        $this->addBinding($bindings, 'from');

        return $this;
    }

    /**
     * Creates a subquery and parse it.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     */
    protected function createSub(Closure|self|EloquentBuilder|string $query): array
    {
        // If the given query is a Closure, we will execute it while passing in a new
        // query instance to the Closure. This will give the developer a chance to
        // format and work with the query before we cast it to a raw SQL string.
        if ($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->forSubQuery());
        }

        return $this->parseSub($query);
    }

    /**
     * Parse the subquery into SQL and bindings.
     *
     * @throws InvalidArgumentException
     */
    protected function parseSub(mixed $query): array
    {
        if ($query instanceof self || $query instanceof EloquentBuilder || $query instanceof Relation) {
            $query = $this->prependDatabaseNameIfCrossDatabaseQuery($query);

            return [$query->toSql(), $query->getBindings()];
        }
        if (is_string($query)) {
            return [$query, []];
        }
        throw new InvalidArgumentException(
            'A subquery must be a query builder instance, a Closure, or a string.'
        );
    }

    /**
     * Prepend the database name if the given query is on another database.
     */
    protected function prependDatabaseNameIfCrossDatabaseQuery(self|EloquentBuilder|Relation $query): self|EloquentBuilder|Relation
    {
        if ($query->getConnection()->getDatabaseName()
            !== $this->getConnection()->getDatabaseName()) {
            $databaseName = $query->getConnection()->getDatabaseName();

            if (! str_starts_with($query->from, $databaseName) && ! str_contains($query->from, '.')) {
                $query->from($databaseName . '.' . $query->from);
            }
        }

        return $query;
    }

    /**
     * Add a new select column to the query.
     */
    public function addSelect(mixed $column): static
    {
        $columns = is_array($column) ? $column : func_get_args();

        foreach ($columns as $as => $column) {
            if (is_string($as) && $this->isQueryable($column)) {
                if (is_null($this->columns)) {
                    $this->select($this->from . '.*');
                }

                $this->selectSub($column, $as);
            } else {
                if (is_array($this->columns) && in_array($column, $this->columns, true)) {
                    continue;
                }

                $this->columns[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add a vector-similarity selection to the query.
     *
     * @param array<int, float>|\Hypervel\Contracts\Support\Arrayable|\Hypervel\Support\Collection<int, float>|string $vector
     */
    public function selectVectorDistance(ExpressionContract|string $column, Collection|Arrayable|array|string $vector, ?string $as = null): static
    {
        $this->ensureConnectionSupportsVectors();

        if (is_string($vector)) {
            $vector = Str::of($vector)->toEmbeddings(cache: true);
        }

        $this->addBinding(
            json_encode(
                $vector instanceof Arrayable
                    ? $vector->toArray()
                    : $vector,
                flags: JSON_THROW_ON_ERROR
            ),
            'select',
        );

        $as = $this->getGrammar()->wrap($as ?? $column . '_distance');

        return $this->addSelect(
            new Expression("({$this->getGrammar()->wrap($column)} <=> ?) as {$as}")
        );
    }

    /**
     * Force the query to only return distinct results.
     */
    public function distinct(): static
    {
        $columns = func_get_args();

        if (count($columns) > 0) {
            $this->distinct = is_array($columns[0]) || is_bool($columns[0]) ? $columns[0] : $columns;
        } else {
            $this->distinct = true;
        }

        return $this;
    }

    /**
     * Set the table which the query is targeting.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Contracts\Database\Query\Expression|string  $table
     */
    public function from(Closure|self|EloquentBuilder|ExpressionContract|string $table, ?string $as = null): static
    {
        if ($this->isQueryable($table)) {
            return $this->fromSub($table, $as);
        }

        $this->from = $as ? "{$table} as {$as}" : $table;

        return $this;
    }

    /**
     * Add an index hint to suggest a query index.
     */
    public function useIndex(string $index): static
    {
        $this->indexHint = new IndexHint('hint', $index);

        return $this;
    }

    /**
     * Add an index hint to force a query index.
     */
    public function forceIndex(string $index): static
    {
        $this->indexHint = new IndexHint('force', $index);

        return $this;
    }

    /**
     * Add an index hint to ignore a query index.
     */
    public function ignoreIndex(string $index): static
    {
        $this->indexHint = new IndexHint('ignore', $index);

        return $this;
    }

    /**
     * Add a "join" clause to the query.
     */
    public function join(ExpressionContract|string $table, Closure|ExpressionContract|string $first, ?string $operator = null, mixed $second = null, string $type = 'inner', bool $where = false): static
    {
        $join = $this->newJoinClause($this, $type, $table);

        // If the first "column" of the join is really a Closure instance the developer
        // is trying to build a join with a complex "on" clause containing more than
        // one condition, so we'll add the join and call a Closure with the query.
        if ($first instanceof Closure) {
            $first($join);

            $this->joins[] = $join;

            $this->addBinding($join->getBindings(), 'join');
        }

        // If the column is simply a string, we can assume the join simply has a basic
        // "on" clause with a single condition. So we will just build the join with
        // this simple join clauses attached to it. There is not a join callback.
        else {
            $method = $where ? 'where' : 'on';

            $this->joins[] = $join->{$method}($first, $operator, $second);

            $this->addBinding($join->getBindings(), 'join');
        }

        return $this;
    }

    /**
     * Add a "join where" clause to the query.
     */
    public function joinWhere(ExpressionContract|string $table, Closure|ExpressionContract|string $first, string $operator, ExpressionContract|string $second, string $type = 'inner'): static
    {
        return $this->join($table, $first, $operator, $second, $type, true);
    }

    /**
     * Add a "subquery join" clause to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     *
     * @throws InvalidArgumentException
     */
    public function joinSub(Closure|self|EloquentBuilder|string $query, string $as, Closure|ExpressionContract|string $first, ?string $operator = null, mixed $second = null, string $type = 'inner', bool $where = false): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '(' . $query . ') as ' . $this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        return $this->join(new Expression($expression), $first, $operator, $second, $type, $where);
    }

    /**
     * Add a "lateral join" clause to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     */
    public function joinLateral(Closure|self|EloquentBuilder|string $query, string $as, string $type = 'inner'): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '(' . $query . ') as ' . $this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinLateralClause($this, $type, new Expression($expression));

        return $this;
    }

    /**
     * Add a lateral left join to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     */
    public function leftJoinLateral(Closure|self|EloquentBuilder|string $query, string $as): static
    {
        return $this->joinLateral($query, $as, 'left');
    }

    /**
     * Add a left join to the query.
     */
    public function leftJoin(ExpressionContract|string $table, Closure|ExpressionContract|string $first, ?string $operator = null, ExpressionContract|string|null $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a "join where" clause to the query.
     */
    public function leftJoinWhere(ExpressionContract|string $table, Closure|ExpressionContract|string $first, string $operator, ExpressionContract|string|null $second): static
    {
        return $this->joinWhere($table, $first, $operator, $second, 'left');
    }

    /**
     * Add a subquery left join to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     */
    public function leftJoinSub(Closure|self|EloquentBuilder|string $query, string $as, Closure|ExpressionContract|string $first, ?string $operator = null, ExpressionContract|string|null $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'left');
    }

    /**
     * Add a right join to the query.
     */
    public function rightJoin(ExpressionContract|string $table, Closure|string $first, ?string $operator = null, ExpressionContract|string|null $second = null): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a "right join where" clause to the query.
     */
    public function rightJoinWhere(ExpressionContract|string $table, Closure|ExpressionContract|string $first, string $operator, ExpressionContract|string $second): static
    {
        return $this->joinWhere($table, $first, $operator, $second, 'right');
    }

    /**
     * Add a subquery right join to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|string  $query
     */
    public function rightJoinSub(Closure|self|EloquentBuilder|string $query, string $as, Closure|ExpressionContract|string $first, ?string $operator = null, ExpressionContract|string|null $second = null): static
    {
        return $this->joinSub($query, $as, $first, $operator, $second, 'right');
    }

    /**
     * Add a "cross join" clause to the query.
     */
    public function crossJoin(ExpressionContract|string $table, Closure|ExpressionContract|string|null $first = null, ?string $operator = null, ExpressionContract|string|null $second = null): static
    {
        if ($first) {
            return $this->join($table, $first, $operator, $second, 'cross');
        }

        $this->joins[] = $this->newJoinClause($this, 'cross', $table);

        return $this;
    }

    /**
     * Add a subquery cross join to the query.
     */
    public function crossJoinSub(Closure|self|EloquentBuilder|string $query, string $as): static
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '(' . $query . ') as ' . $this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinClause($this, 'cross', new Expression($expression));

        return $this;
    }

    /**
     * Get a new "join" clause.
     */
    protected function newJoinClause(self $parentQuery, string $type, ExpressionContract|string $table): JoinClause
    {
        return new JoinClause($parentQuery, $type, $table);
    }

    /**
     * Get a new "join lateral" clause.
     */
    protected function newJoinLateralClause(self $parentQuery, string $type, ExpressionContract|string $table): JoinLateralClause
    {
        return new JoinLateralClause($parentQuery, $type, $table);
    }

    /**
     * Merge an array of "where" clauses and bindings.
     */
    public function mergeWheres(array $wheres, array $bindings): static
    {
        $this->wheres = array_merge($this->wheres, (array) $wheres);

        $this->bindings['where'] = array_values(
            array_merge($this->bindings['where'], (array) $bindings)
        );

        return $this;
    }

    /**
     * Add a basic "where" clause to the query.
     */
    public function where(Closure|self|EloquentBuilder|Relation|ExpressionContract|array|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof ConditionExpression) {
            $type = 'Expression';

            $this->wheres[] = compact('type', 'column', 'boolean');

            return $this;
        }

        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the column is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parentheses.
        // We will add that Closure to the query and return back out immediately.
        if ($column instanceof Closure && is_null($operator)) {
            return $this->whereNested($column, $boolean);
        }

        // If the column is a Closure instance and there is an operator value, we will
        // assume the developer wants to run a subquery and then compare the result
        // of that subquery with the given value that was provided to the method.
        if ($this->isQueryable($column) && ! is_null($operator)) {
            [$sub, $bindings] = $this->createSub($column);

            return $this->addBinding($bindings, 'where')
                ->where(new Expression('(' . $sub . ')'), $operator, $value, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        // If the value is a Closure, it means the developer is performing an entire
        // sub-select within the query and we will need to compile the sub-select
        // within the where clause to get the appropriate query record results.
        if ($this->isQueryable($value)) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        // If the value is "null", we will just assume the developer wants to add a
        // where null clause to the query. So, we will allow a short-cut here to
        // that method for convenience so the developer doesn't have to check.
        if (is_null($value)) {
            return $this->whereNull($column, $boolean, ! in_array($operator, ['=', '<=>'], true));
        }

        $type = 'Basic';

        $columnString = ($column instanceof ExpressionContract)
            ? $this->grammar->getValue($column)
            : $column;

        // If the column is making a JSON reference we'll check to see if the value
        // is a boolean. If it is, we'll add the raw boolean string as an actual
        // value to the query to ensure this is properly handled by the query.
        if (str_contains($columnString, '->') && is_bool($value)) {
            $value = new Expression($value ? 'true' : 'false');

            if (is_string($column)) {
                $type = 'JsonBoolean';
            }
        }

        if ($this->isBitwiseOperator($operator)) {
            $type = 'Bitwise';
        }

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be bound to each SQL statements when it is finally executed.
        $this->wheres[] = compact(
            'type',
            'column',
            'operator',
            'value',
            'boolean'
        );

        if (! $value instanceof ExpressionContract) {
            $this->addBinding($this->flattenValue($value), 'where');
        }

        return $this;
    }

    /**
     * Add an array of "where" clauses to the query.
     */
    protected function addArrayOfWheres(array $column, string $boolean, string $method = 'where'): static
    {
        return $this->whereNested(function ($query) use ($column, $method, $boolean) {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $query->{$method}(...array_values($value), boolean: $boolean);
                } else {
                    $query->{$method}($key, '=', $value, $boolean);
                }
            }
        }, $boolean);
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     * @throws InvalidArgumentException
     */
    public function prepareValueAndOperator(mixed $value, mixed $operator, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        }
        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     */
    protected function invalidOperatorAndValue(mixed $operator, mixed $value): bool
    {
        return is_null($value) && in_array($operator, $this->operators)
             && ! in_array($operator, ['=', '<=>', '<>', '!=']);
    }

    /**
     * Determine if the given operator is supported.
     */
    protected function invalidOperator(mixed $operator): bool
    {
        return ! is_string($operator) || (! in_array(strtolower($operator), $this->operators, true)
               && ! in_array(strtolower($operator), $this->grammar->getOperators(), true));
    }

    /**
     * Determine if the operator is a bitwise operator.
     */
    protected function isBitwiseOperator(string $operator): bool
    {
        return in_array(strtolower($operator), $this->bitwiseOperators, true)
               || in_array(strtolower($operator), $this->grammar->getBitwiseOperators(), true);
    }

    /**
     * Add an "or where" clause to the query.
     */
    public function orWhere(Closure|string|array|ExpressionContract $column, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a basic "where not" clause to the query.
     */
    public function whereNot(Closure|string|array|ExpressionContract $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (is_array($column)) {
            return $this->whereNested(function ($query) use ($column, $operator, $value, $boolean) {
                $query->where($column, $operator, $value, $boolean);
            }, $boolean . ' not');
        }

        return $this->where($column, $operator, $value, $boolean . ' not');
    }

    /**
     * Add an "or where not" clause to the query.
     */
    public function orWhereNot(Closure|string|array|ExpressionContract $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereNot($column, $operator, $value, 'or');
    }

    /**
     * Add a "where" clause comparing two columns to the query.
     */
    public function whereColumn(ExpressionContract|string|array $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): static
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($first)) {
            return $this->addArrayOfWheres($first, $boolean, 'whereColumn');
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$second, $operator] = [$operator, '='];
        }

        // Finally, we will add this where clause into this array of clauses that we
        // are building for the query. All of them will be compiled via a grammar
        // once the query is about to be executed and run against the database.
        $type = 'Column';

        $this->wheres[] = compact(
            'type',
            'first',
            'operator',
            'second',
            'boolean'
        );

        return $this;
    }

    /**
     * Add an "or where" clause comparing two columns to the query.
     */
    public function orWhereColumn(ExpressionContract|string|array $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /**
     * Add a vector similarity clause to the query, filtering by minimum similarity and ordering by similarity.
     *
     * @param array<int, float>|\Hypervel\Contracts\Support\Arrayable|\Hypervel\Support\Collection<int, float>|string $vector
     * @param float $minSimilarity A value between 0.0 and 1.0, where 1.0 is identical.
     */
    public function whereVectorSimilarTo(ExpressionContract|string $column, Collection|Arrayable|array|string $vector, float $minSimilarity = 0.6, bool $order = true): static
    {
        if (is_string($vector)) {
            $vector = Str::of($vector)->toEmbeddings(cache: true);
        }

        $this->whereVectorDistanceLessThan($column, $vector, 1 - $minSimilarity);

        if ($order) {
            $this->orderByVectorDistance($column, $vector);
        }

        return $this;
    }

    /**
     * Add a vector distance "where" clause to the query.
     *
     * @param array<int, float>|\Hypervel\Contracts\Support\Arrayable|\Hypervel\Support\Collection<int, float>|string $vector
     */
    public function whereVectorDistanceLessThan(ExpressionContract|string $column, Collection|Arrayable|array|string $vector, float $maxDistance, string $boolean = 'and'): static
    {
        $this->ensureConnectionSupportsVectors();

        if (is_string($vector)) {
            $vector = Str::of($vector)->toEmbeddings(cache: true);
        }

        return $this->whereRaw(
            "({$this->getGrammar()->wrap($column)} <=> ?) <= ?",
            [
                json_encode(
                    $vector instanceof Arrayable
                        ? $vector->toArray()
                        : $vector,
                    flags: JSON_THROW_ON_ERROR
                ),
                $maxDistance,
            ],
            $boolean
        );
    }

    /**
     * Add a vector distance "or where" clause to the query.
     *
     * @param array<int, float>|\Hypervel\Contracts\Support\Arrayable|\Hypervel\Support\Collection<int, float>|string $vector
     */
    public function orWhereVectorDistanceLessThan(ExpressionContract|string $column, Collection|Arrayable|array|string $vector, float $maxDistance): static
    {
        return $this->whereVectorDistanceLessThan($column, $vector, $maxDistance, 'or');
    }

    /**
     * Add a raw "where" clause to the query.
     */
    public function whereRaw(ExpressionContract|string $sql, mixed $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean];

        $this->addBinding((array) $bindings, 'where');

        return $this;
    }

    /**
     * Add a raw "or where" clause to the query.
     */
    public function orWhereRaw(string $sql, mixed $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a "where like" clause to the query.
     */
    public function whereLike(ExpressionContract|string $column, string $value, bool $caseSensitive = false, string $boolean = 'and', bool $not = false): static
    {
        $type = 'Like';

        $this->wheres[] = compact('type', 'column', 'value', 'caseSensitive', 'boolean', 'not');

        if (method_exists($this->grammar, 'prepareWhereLikeBinding')) {
            $value = $this->grammar->prepareWhereLikeBinding($value, $caseSensitive);
        }

        $this->addBinding($value);

        return $this;
    }

    /**
     * Add an "or where like" clause to the query.
     */
    public function orWhereLike(ExpressionContract|string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'or', false);
    }

    /**
     * Add a "where not like" clause to the query.
     */
    public function whereNotLike(ExpressionContract|string $column, string $value, bool $caseSensitive = false, string $boolean = 'and'): static
    {
        return $this->whereLike($column, $value, $caseSensitive, $boolean, true);
    }

    /**
     * Add an "or where not like" clause to the query.
     */
    public function orWhereNotLike(ExpressionContract|string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereNotLike($column, $value, $caseSensitive, 'or');
    }

    /**
     * Add a "where in" clause to the query.
     */
    public function whereIn(ExpressionContract|string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotIn' : 'In';

        // If the value is a query builder instance we will assume the developer wants to
        // look for any values that exist within this given query. So, we will add the
        // query accordingly so that this query is properly executed when it is run.
        if ($this->isQueryable($values)) {
            [$query, $bindings] = $this->createSub($values);

            $values = [new Expression($query)];

            $this->addBinding($bindings, 'where');
        }

        // Next, if the value is Arrayable we need to cast it to its raw array form so we
        // have the underlying array value instead of an Arrayable object which is not
        // able to be added as a binding, etc. We will then add to the wheres array.
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        if (count($values) !== count(Arr::flatten($values, 1))) {
            throw new InvalidArgumentException('Nested arrays may not be passed to whereIn method.');
        }

        // Finally, we'll add a binding for each value unless that value is an expression
        // in which case we will just skip over it since it will be the query as a raw
        // string and not as a parameterized place-holder to be replaced by the PDO.
        $this->addBinding($this->cleanBindings($values), 'where');

        return $this;
    }

    /**
     * Add an "or where in" clause to the query.
     */
    public function orWhereIn(ExpressionContract|string $column, mixed $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * Add a "where not in" clause to the query.
     */
    public function whereNotIn(ExpressionContract|string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in" clause to the query.
     */
    public function orWhereNotIn(ExpressionContract|string $column, mixed $values): static
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Add a "where in raw" clause for integer values to the query.
     */
    public function whereIntegerInRaw(string $column, Arrayable|array $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotInRaw' : 'InRaw';

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $values = Arr::flatten($values);

        foreach ($values as &$value) {
            $value = (int) ($value instanceof BackedEnum ? $value->value : $value);
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        return $this;
    }

    /**
     * Add an "or where in raw" clause for integer values to the query.
     */
    public function orWhereIntegerInRaw(string $column, Arrayable|array $values): static
    {
        return $this->whereIntegerInRaw($column, $values, 'or');
    }

    /**
     * Add a "where not in raw" clause for integer values to the query.
     */
    public function whereIntegerNotInRaw(string $column, Arrayable|array $values, string $boolean = 'and'): static
    {
        return $this->whereIntegerInRaw($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in raw" clause for integer values to the query.
     */
    public function orWhereIntegerNotInRaw(string $column, Arrayable|array $values): static
    {
        return $this->whereIntegerNotInRaw($column, $values, 'or');
    }

    /**
     * Add a "where null" clause to the query.
     */
    public function whereNull(string|array|ExpressionContract $columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotNull' : 'Null';

        foreach (Arr::wrap($columns) as $column) {
            $this->wheres[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add an "or where null" clause to the query.
     */
    public function orWhereNull(string|array|ExpressionContract $column): static
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * Add a "where not null" clause to the query.
     */
    public function whereNotNull(string|array|ExpressionContract $columns, string $boolean = 'and'): static
    {
        return $this->whereNull($columns, $boolean, true);
    }

    /**
     * Add a "where between" statement to the query.
     *
     * @param  \Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Contracts\Database\Query\Expression|string  $column
     */
    public function whereBetween(self|EloquentBuilder|ExpressionContract|string $column, iterable $values, string $boolean = 'and', bool $not = false): static
    {
        $type = 'between';

        if ($this->isQueryable($column)) {
            [$sub, $bindings] = $this->createSub($column);

            return $this->addBinding($bindings, 'where')
                ->whereBetween(new Expression('(' . $sub . ')'), $values, $boolean, $not);
        }

        if ($values instanceof CarbonPeriod) {
            $values = [$values->getStartDate(), $values->getEndDate()];
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        $this->addBinding(array_slice($this->cleanBindings(Arr::flatten($values)), 0, 2), 'where');

        return $this;
    }

    /**
     * Add a "where between" statement using columns to the query.
     *
     * @param  \Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Contracts\Database\Query\Expression|string  $column
     */
    public function whereBetweenColumns(self|EloquentBuilder|ExpressionContract|string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $type = 'betweenColumns';

        if ($this->isQueryable($column)) {
            [$sub, $bindings] = $this->createSub($column);

            return $this->addBinding($bindings, 'where')
                ->whereBetweenColumns(new Expression('(' . $sub . ')'), $values, $boolean, $not);
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        return $this;
    }

    /**
     * Add an "or where between" statement to the query.
     *
     * @param  \Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Contracts\Database\Query\Expression|string  $column
     */
    public function orWhereBetween(self|EloquentBuilder|ExpressionContract|string $column, iterable $values): static
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * Add an "or where between" statement using columns to the query.
     */
    public function orWhereBetweenColumns(ExpressionContract|string $column, array $values): static
    {
        return $this->whereBetweenColumns($column, $values, 'or');
    }

    /**
     * Add a "where not between" statement to the query.
     *
     * @param  \Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Contracts\Database\Query\Expression|string  $column
     */
    public function whereNotBetween(self|EloquentBuilder|ExpressionContract|string $column, iterable $values, string $boolean = 'and'): static
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add a "where not between" statement using columns to the query.
     */
    public function whereNotBetweenColumns(ExpressionContract|string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereBetweenColumns($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not between" statement to the query.
     *
     * @param  \Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Contracts\Database\Query\Expression|string  $column
     */
    public function orWhereNotBetween(self|EloquentBuilder|ExpressionContract|string $column, iterable $values): static
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    /**
     * Add an "or where not between" statement using columns to the query.
     */
    public function orWhereNotBetweenColumns(ExpressionContract|string $column, array $values): static
    {
        return $this->whereNotBetweenColumns($column, $values, 'or');
    }

    /**
     * Add a "where between columns" statement using a value to the query.
     *
     * @param array{\Hypervel\Contracts\Database\Query\Expression|string, \Hypervel\Contracts\Database\Query\Expression|string} $columns
     */
    public function whereValueBetween(mixed $value, array $columns, string $boolean = 'and', bool $not = false): static
    {
        $type = 'valueBetween';

        $this->wheres[] = compact('type', 'value', 'columns', 'boolean', 'not');

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Add an "or where between columns" statement using a value to the query.
     *
     * @param array{\Hypervel\Contracts\Database\Query\Expression|string, \Hypervel\Contracts\Database\Query\Expression|string} $columns
     */
    public function orWhereValueBetween(mixed $value, array $columns): static
    {
        return $this->whereValueBetween($value, $columns, 'or');
    }

    /**
     * Add a "where not between columns" statement using a value to the query.
     *
     * @param array{\Hypervel\Contracts\Database\Query\Expression|string, \Hypervel\Contracts\Database\Query\Expression|string} $columns
     */
    public function whereValueNotBetween(mixed $value, array $columns, string $boolean = 'and'): static
    {
        return $this->whereValueBetween($value, $columns, $boolean, true);
    }

    /**
     * Add an "or where not between columns" statement using a value to the query.
     *
     * @param array{\Hypervel\Contracts\Database\Query\Expression|string, \Hypervel\Contracts\Database\Query\Expression|string} $columns
     */
    public function orWhereValueNotBetween(mixed $value, array $columns): static
    {
        return $this->whereValueNotBetween($value, $columns, 'or');
    }

    /**
     * Add an "or where not null" clause to the query.
     */
    public function orWhereNotNull(array|ExpressionContract|string $column): static
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * Add a "where date" statement to the query.
     */
    public function whereDate(ExpressionContract|string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        return $this->addDateBasedWhere('Date', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where date" statement to the query.
     */
    public function orWhereDate(ExpressionContract|string $column, mixed $operator, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->whereDate($column, $operator, $value, 'or');
    }

    /**
     * Add a "where time" statement to the query.
     */
    public function whereTime(ExpressionContract|string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('H:i:s');
        }

        return $this->addDateBasedWhere('Time', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where time" statement to the query.
     */
    public function orWhereTime(ExpressionContract|string $column, mixed $operator, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->whereTime($column, $operator, $value, 'or');
    }

    /**
     * Add a "where day" statement to the query.
     */
    public function whereDay(ExpressionContract|string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('d');
        }

        if (! $value instanceof ExpressionContract) {
            $value = sprintf('%02d', $value);
        }

        return $this->addDateBasedWhere('Day', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where day" statement to the query.
     */
    public function orWhereDay(ExpressionContract|string $column, mixed $operator, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->whereDay($column, $operator, $value, 'or');
    }

    /**
     * Add a "where month" statement to the query.
     */
    public function whereMonth(ExpressionContract|string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('m');
        }

        if (! $value instanceof ExpressionContract) {
            $value = sprintf('%02d', $value);
        }

        return $this->addDateBasedWhere('Month', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where month" statement to the query.
     */
    public function orWhereMonth(ExpressionContract|string $column, mixed $operator, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->whereMonth($column, $operator, $value, 'or');
    }

    /**
     * Add a "where year" statement to the query.
     */
    public function whereYear(ExpressionContract|string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y');
        }

        return $this->addDateBasedWhere('Year', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where year" statement to the query.
     */
    public function orWhereYear(ExpressionContract|string $column, mixed $operator, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->whereYear($column, $operator, $value, 'or');
    }

    /**
     * Add a date based (year, month, day, time) statement to the query.
     */
    protected function addDateBasedWhere(string $type, ExpressionContract|string $column, string $operator, mixed $value, string $boolean = 'and'): static
    {
        $this->wheres[] = compact('column', 'type', 'boolean', 'operator', 'value');

        if (! $value instanceof ExpressionContract) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    /**
     * Add a nested "where" statement to the query.
     */
    public function whereNested(Closure $callback, string $boolean = 'and'): static
    {
        $callback($query = $this->forNestedWhere());

        return $this->addNestedWhereQuery($query, $boolean);
    }

    /**
     * Create a new query instance for nested where condition.
     */
    public function forNestedWhere(): self
    {
        $query = $this->newQuery();

        if (! is_null($this->from)) {
            $query->from($this->from);
        }

        return $query;
    }

    /**
     * Add another query builder as a nested where to the query builder.
     */
    public function addNestedWhereQuery(self $query, string $boolean = 'and'): static
    {
        if (count($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query', 'boolean');

            $this->addBinding($query->getRawBindings()['where'], 'where');
        }

        return $this;
    }

    /**
     * Add a full sub-select to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>  $callback
     */
    protected function whereSub(ExpressionContract|string $column, string $operator, Closure|self|EloquentBuilder $callback, string $boolean): static
    {
        $type = 'Sub';

        if ($callback instanceof Closure) {
            // Once we have the query instance we can simply execute it so it can add all
            // of the sub-select's conditions to itself, and then we can cache it off
            // in the array of where clauses for the "main" parent query instance.
            $callback($query = $this->forSubQuery());
        } else {
            $query = $callback instanceof EloquentBuilder ? $callback->toBase() : $callback;
        }

        $this->wheres[] = compact(
            'type',
            'column',
            'operator',
            'query',
            'boolean'
        );

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an "exists" clause to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>  $callback
     */
    public function whereExists(Closure|self|EloquentBuilder $callback, string $boolean = 'and', bool $not = false): static
    {
        if ($callback instanceof Closure) {
            $query = $this->forSubQuery();

            // Similar to the sub-select clause, we will create a new query instance so
            // the developer may cleanly specify the entire exists query and we will
            // compile the whole thing in the grammar and insert it into the SQL.
            $callback($query);
        } else {
            $query = $callback instanceof EloquentBuilder ? $callback->toBase() : $callback;
        }

        return $this->addWhereExistsQuery($query, $boolean, $not);
    }

    /**
     * Add an "or where exists" clause to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>  $callback
     */
    public function orWhereExists(Closure|self|EloquentBuilder $callback, bool $not = false): static
    {
        return $this->whereExists($callback, 'or', $not);
    }

    /**
     * Add a "where not exists" clause to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>  $callback
     */
    public function whereNotExists(Closure|self|EloquentBuilder $callback, string $boolean = 'and'): static
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add an "or where not exists" clause to the query.
     *
     * @param  \Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>  $callback
     */
    public function orWhereNotExists(Closure|self|EloquentBuilder $callback): static
    {
        return $this->orWhereExists($callback, true);
    }

    /**
     * Add an "exists" clause to the query.
     */
    public function addWhereExistsQuery(self $query, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotExists' : 'Exists';

        $this->wheres[] = compact('type', 'query', 'boolean');

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Adds a where condition using row values.
     *
     * @throws InvalidArgumentException
     */
    public function whereRowValues(array $columns, string $operator, array $values, string $boolean = 'and'): static
    {
        if (count($columns) !== count($values)) {
            throw new InvalidArgumentException('The number of columns must match the number of values');
        }

        $type = 'RowValues';

        $this->wheres[] = compact('type', 'columns', 'operator', 'values', 'boolean');

        $this->addBinding($this->cleanBindings($values));

        return $this;
    }

    /**
     * Adds an or where condition using row values.
     */
    public function orWhereRowValues(array $columns, string $operator, array $values): static
    {
        return $this->whereRowValues($columns, $operator, $values, 'or');
    }

    /**
     * Add a "where JSON contains" clause to the query.
     */
    public function whereJsonContains(string $column, mixed $value, string $boolean = 'and', bool $not = false): static
    {
        $type = 'JsonContains';

        $this->wheres[] = compact('type', 'column', 'value', 'boolean', 'not');

        if (! $value instanceof ExpressionContract) {
            $this->addBinding($this->grammar->prepareBindingForJsonContains($value));
        }

        return $this;
    }

    /**
     * Add an "or where JSON contains" clause to the query.
     */
    public function orWhereJsonContains(string $column, mixed $value): static
    {
        return $this->whereJsonContains($column, $value, 'or');
    }

    /**
     * Add a "where JSON not contains" clause to the query.
     */
    public function whereJsonDoesntContain(string $column, mixed $value, string $boolean = 'and'): static
    {
        return $this->whereJsonContains($column, $value, $boolean, true);
    }

    /**
     * Add an "or where JSON not contains" clause to the query.
     */
    public function orWhereJsonDoesntContain(string $column, mixed $value): static
    {
        return $this->whereJsonDoesntContain($column, $value, 'or');
    }

    /**
     * Add a "where JSON overlaps" clause to the query.
     */
    public function whereJsonOverlaps(string $column, mixed $value, string $boolean = 'and', bool $not = false): static
    {
        $type = 'JsonOverlaps';

        $this->wheres[] = compact('type', 'column', 'value', 'boolean', 'not');

        if (! $value instanceof ExpressionContract) {
            $this->addBinding($this->grammar->prepareBindingForJsonContains($value));
        }

        return $this;
    }

    /**
     * Add an "or where JSON overlaps" clause to the query.
     */
    public function orWhereJsonOverlaps(string $column, mixed $value): static
    {
        return $this->whereJsonOverlaps($column, $value, 'or');
    }

    /**
     * Add a "where JSON not overlap" clause to the query.
     */
    public function whereJsonDoesntOverlap(string $column, mixed $value, string $boolean = 'and'): static
    {
        return $this->whereJsonOverlaps($column, $value, $boolean, true);
    }

    /**
     * Add an "or where JSON not overlap" clause to the query.
     */
    public function orWhereJsonDoesntOverlap(string $column, mixed $value): static
    {
        return $this->whereJsonDoesntOverlap($column, $value, 'or');
    }

    /**
     * Add a clause that determines if a JSON path exists to the query.
     */
    public function whereJsonContainsKey(string $column, string $boolean = 'and', bool $not = false): static
    {
        $type = 'JsonContainsKey';

        $this->wheres[] = compact('type', 'column', 'boolean', 'not');

        return $this;
    }

    /**
     * Add an "or" clause that determines if a JSON path exists to the query.
     */
    public function orWhereJsonContainsKey(string $column): static
    {
        return $this->whereJsonContainsKey($column, 'or');
    }

    /**
     * Add a clause that determines if a JSON path does not exist to the query.
     */
    public function whereJsonDoesntContainKey(string $column, string $boolean = 'and'): static
    {
        return $this->whereJsonContainsKey($column, $boolean, true);
    }

    /**
     * Add an "or" clause that determines if a JSON path does not exist to the query.
     */
    public function orWhereJsonDoesntContainKey(string $column): static
    {
        return $this->whereJsonDoesntContainKey($column, 'or');
    }

    /**
     * Add a "where JSON length" clause to the query.
     */
    public function whereJsonLength(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        $type = 'JsonLength';

        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (! $value instanceof ExpressionContract) {
            $this->addBinding((int) $this->flattenValue($value));
        }

        return $this;
    }

    /**
     * Add an "or where JSON length" clause to the query.
     */
    public function orWhereJsonLength(string $column, mixed $operator, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->whereJsonLength($column, $operator, $value, 'or');
    }

    /**
     * Handles dynamic "where" clauses to the query.
     */
    public function dynamicWhere(string $method, array $parameters): static
    {
        $finder = substr($method, 5);

        $segments = preg_split(
            '/(And|Or)(?=[A-Z])/',
            $finder,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        // The connector variable will determine which connector will be used for the
        // query condition. We will change it as we come across new boolean values
        // in the dynamic method strings, which could contain a number of these.
        $connector = 'and';

        $index = 0;

        foreach ($segments as $segment) {
            // If the segment is not a boolean connector, we can assume it is a column's name
            // and we will add it to the query as a new constraint as a where clause, then
            // we can keep iterating through the dynamic method string's segments again.
            if ($segment !== 'And' && $segment !== 'Or') {
                $this->addDynamic($segment, $connector, $parameters, $index);

                ++$index;
            }

            // Otherwise, we will store the connector so we know how the next where clause we
            // find in the query should be connected to the previous ones, meaning we will
            // have the proper boolean connector to connect the next where clause found.
            else {
                $connector = $segment;
            }
        }

        return $this;
    }

    /**
     * Add a single dynamic "where" clause statement to the query.
     */
    protected function addDynamic(string $segment, string $connector, array $parameters, int $index): void
    {
        // Once we have parsed out the columns and formatted the boolean operators we
        // are ready to add it to this query as a where clause just like any other
        // clause on the query. Then we'll increment the parameter index values.
        $bool = strtolower($connector);

        $this->where(StrCache::snake($segment), '=', $parameters[$index], $bool);
    }

    /**
     * Add a "where fulltext" clause to the query.
     */
    public function whereFullText(string|array $columns, string $value, array $options = [], string $boolean = 'and'): static
    {
        $type = 'Fulltext';

        $columns = (array) $columns;

        $this->wheres[] = compact('type', 'columns', 'value', 'options', 'boolean');

        $this->addBinding($value);

        return $this;
    }

    /**
     * Add an "or where fulltext" clause to the query.
     */
    public function orWhereFullText(string|array $columns, string $value, array $options = []): static
    {
        return $this->whereFullText($columns, $value, $options, 'or');
    }

    /**
     * Add a "where" clause to the query for multiple columns with "and" conditions between them.
     *
     * @param array<Closure|ExpressionContract|string> $columns
     */
    public function whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        $this->whereNested(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'and');
            }
        }, $boolean);

        return $this;
    }

    /**
     * Add an "or where" clause to the query for multiple columns with "and" conditions between them.
     *
     * @param array<Closure|ExpressionContract|string> $columns
     */
    public function orWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereAll($columns, $operator, $value, 'or');
    }

    /**
     * Add a "where" clause to the query for multiple columns with "or" conditions between them.
     *
     * @param array<Closure|ExpressionContract|string> $columns
     */
    public function whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        $this->whereNested(function ($query) use ($columns, $operator, $value) {
            foreach ($columns as $column) {
                $query->where($column, $operator, $value, 'or');
            }
        }, $boolean);

        return $this;
    }

    /**
     * Add an "or where" clause to the query for multiple columns with "or" conditions between them.
     *
     * @param array<Closure|ExpressionContract|string> $columns
     */
    public function orWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereAny($columns, $operator, $value, 'or');
    }

    /**
     * Add a "where not" clause to the query for multiple columns where none of the conditions should be true.
     *
     * @param array<Closure|ExpressionContract|string> $columns
     */
    public function whereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->whereAny($columns, $operator, $value, $boolean . ' not');
    }

    /**
     * Add an "or where not" clause to the query for multiple columns where none of the conditions should be true.
     *
     * @param array<Closure|ExpressionContract|string> $columns
     */
    public function orWhereNone(array $columns, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereNone($columns, $operator, $value, 'or');
    }

    /**
     * Add a "group by" clause to the query.
     */
    public function groupBy(array|ExpressionContract|string ...$groups): static
    {
        foreach ($groups as $group) {
            $this->groups = array_merge(
                (array) $this->groups,
                Arr::wrap($group)
            );
        }

        return $this;
    }

    /**
     * Add a raw "groupBy" clause to the query.
     */
    public function groupByRaw(string $sql, array $bindings = []): static
    {
        $this->groups[] = new Expression($sql);

        $this->addBinding($bindings, 'groupBy');

        return $this;
    }

    /**
     * Add a "having" clause to the query.
     */
    public function having(
        ExpressionContract|Closure|string $column,
        DateTimeInterface|string|int|float|null $operator = null,
        ExpressionContract|DateTimeInterface|string|int|float|null $value = null,
        string $boolean = 'and',
    ): static {
        $type = 'Basic';

        if ($column instanceof ConditionExpression) {
            $type = 'Expression';

            $this->havings[] = compact('type', 'column', 'boolean');

            return $this;
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        if ($column instanceof Closure && is_null($operator)) {
            return $this->havingNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        if ($this->isBitwiseOperator($operator)) {
            $type = 'Bitwise';
        }

        $this->havings[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (! $value instanceof ExpressionContract) {
            $this->addBinding($this->flattenValue($value), 'having');
        }

        return $this;
    }

    /**
     * Add an "or having" clause to the query.
     */
    public function orHaving(
        ExpressionContract|Closure|string $column,
        DateTimeInterface|string|int|float|null $operator = null,
        ExpressionContract|DateTimeInterface|string|int|float|null $value = null,
    ): static {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->having($column, $operator, $value, 'or');
    }

    /**
     * Add a nested "having" statement to the query.
     */
    public function havingNested(Closure $callback, string $boolean = 'and'): static
    {
        $callback($query = $this->forNestedWhere());

        return $this->addNestedHavingQuery($query, $boolean);
    }

    /**
     * Add another query builder as a nested having to the query builder.
     */
    public function addNestedHavingQuery(self $query, string $boolean = 'and'): static
    {
        if (count($query->havings)) {
            $type = 'Nested';

            $this->havings[] = compact('type', 'query', 'boolean');

            $this->addBinding($query->getRawBindings()['having'], 'having');
        }

        return $this;
    }

    /**
     * Add a "having null" clause to the query.
     */
    public function havingNull(array|string $columns, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotNull' : 'Null';

        foreach (Arr::wrap($columns) as $column) {
            $this->havings[] = compact('type', 'column', 'boolean');
        }

        return $this;
    }

    /**
     * Add an "or having null" clause to the query.
     */
    public function orHavingNull(string $column): static
    {
        return $this->havingNull($column, 'or');
    }

    /**
     * Add a "having not null" clause to the query.
     */
    public function havingNotNull(array|string $columns, string $boolean = 'and'): static
    {
        return $this->havingNull($columns, $boolean, true);
    }

    /**
     * Add an "or having not null" clause to the query.
     */
    public function orHavingNotNull(string $column): static
    {
        return $this->havingNotNull($column, 'or');
    }

    /**
     * Add a "having between" clause to the query.
     */
    public function havingBetween(string $column, iterable $values, string $boolean = 'and', bool $not = false): static
    {
        $type = 'between';

        if ($values instanceof CarbonPeriod) {
            $values = [$values->getStartDate(), $values->getEndDate()];
        }

        $this->havings[] = compact('type', 'column', 'values', 'boolean', 'not');

        $this->addBinding(array_slice($this->cleanBindings(Arr::flatten($values)), 0, 2), 'having');

        return $this;
    }

    /**
     * Add a "having not between" clause to the query.
     */
    public function havingNotBetween(string $column, iterable $values, string $boolean = 'and'): static
    {
        return $this->havingBetween($column, $values, $boolean, true);
    }

    /**
     * Add an "or having between" clause to the query.
     */
    public function orHavingBetween(string $column, iterable $values): static
    {
        return $this->havingBetween($column, $values, 'or');
    }

    /**
     * Add an "or having not between" clause to the query.
     */
    public function orHavingNotBetween(string $column, iterable $values): static
    {
        return $this->havingBetween($column, $values, 'or', true);
    }

    /**
     * Add a raw "having" clause to the query.
     */
    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $type = 'Raw';

        $this->havings[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'having');

        return $this;
    }

    /**
     * Add a raw "or having" clause to the query.
     */
    public function orHavingRaw(string $sql, array $bindings = []): static
    {
        return $this->havingRaw($sql, $bindings, 'or');
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param  Closure|self|EloquentBuilder<*>|ExpressionContract|string  $column
     *
     * @throws InvalidArgumentException
     */
    public function orderBy(Closure|self|EloquentBuilder|ExpressionContract|string $column, string $direction = 'asc'): static
    {
        if ($this->isQueryable($column)) {
            [$query, $bindings] = $this->createSub($column);

            $column = new Expression('(' . $query . ')');

            $this->addBinding($bindings, $this->unions ? 'unionOrder' : 'order');
        }

        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * Add a descending "order by" clause to the query.
     *
     * @param  Closure|self|EloquentBuilder<*>|ExpressionContract|string  $column
     */
    public function orderByDesc(Closure|self|EloquentBuilder|ExpressionContract|string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     */
    public function latest(Closure|self|ExpressionContract|string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     */
    public function oldest(Closure|self|ExpressionContract|string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Add a vector-distance "order by" clause to the query.
     *
     * @param array<int, float>|Arrayable<int, float>|Collection<int, float>|string $vector
     */
    public function orderByVectorDistance(ExpressionContract|string $column, Collection|Arrayable|array|string $vector): static
    {
        $this->ensureConnectionSupportsVectors();

        if (is_string($vector)) {
            $vector = Str::of($vector)->toEmbeddings(cache: true);
        }

        $this->addBinding(
            json_encode(
                $vector instanceof Arrayable
                    ? $vector->toArray()
                    : $vector,
                flags: JSON_THROW_ON_ERROR
            ),
            $this->unions ? 'unionOrder' : 'order'
        );

        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = [
            'column' => new Expression("({$this->getGrammar()->wrap($column)} <=> ?)"),
            'direction' => 'asc',
        ];

        return $this;
    }

    /**
     * Put the query's results in random order.
     */
    public function inRandomOrder(string|int $seed = ''): static
    {
        return $this->orderByRaw($this->grammar->compileRandom($seed));
    }

    /**
     * Add a raw "order by" clause to the query.
     */
    public function orderByRaw(string $sql, mixed $bindings = []): static
    {
        $type = 'Raw';

        $this->{$this->unions ? 'unionOrders' : 'orders'}[] = compact('type', 'sql');

        $this->addBinding($bindings, $this->unions ? 'unionOrder' : 'order');

        return $this;
    }

    /**
     * Alias to set the "offset" value of the query.
     */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * Set the "offset" value of the query.
     */
    public function offset(?int $value): static
    {
        $property = $this->unions ? 'unionOffset' : 'offset';

        $this->{$property} = max(0, (int) $value);

        return $this;
    }

    /**
     * Alias to set the "limit" value of the query.
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Set the "limit" value of the query.
     */
    public function limit(?int $value): static
    {
        $property = $this->unions ? 'unionLimit' : 'limit';

        if (is_null($value) || $value >= 0) {
            $this->{$property} = $value;
        }

        return $this;
    }

    /**
     * Add a "group limit" clause to the query.
     */
    public function groupLimit(int $value, string $column): static
    {
        if ($value >= 0) {
            $this->groupLimit = compact('value', 'column');
        }

        return $this;
    }

    /**
     * Set the limit and offset for a given page.
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Constrain the query to the previous "page" of results before a given ID.
     */
    public function forPageBeforeId(int $perPage = 15, ?int $lastId = 0, string $column = 'id'): static
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if (is_null($lastId)) {
            $this->whereNotNull($column);
        } else {
            $this->where($column, '<', $lastId);
        }

        return $this->orderBy($column, 'desc')
            ->limit($perPage);
    }

    /**
     * Constrain the query to the next "page" of results after a given ID.
     */
    public function forPageAfterId(int $perPage = 15, string|int|null $lastId = 0, string $column = 'id'): static
    {
        $this->orders = $this->removeExistingOrdersFor($column);

        if (is_null($lastId)) {
            $this->whereNotNull($column);
        } else {
            $this->where($column, '>', $lastId);
        }

        return $this->orderBy($column, 'asc')
            ->limit($perPage);
    }

    /**
     * Remove all existing orders and optionally add a new order.
     */
    public function reorder(Closure|self|ExpressionContract|string|null $column = null, string $direction = 'asc'): static
    {
        $this->orders = null;
        $this->unionOrders = null;
        $this->bindings['order'] = [];
        $this->bindings['unionOrder'] = [];

        if ($column) {
            return $this->orderBy($column, $direction);
        }

        return $this;
    }

    /**
     * Add descending "reorder" clause to the query.
     */
    public function reorderDesc(Closure|self|ExpressionContract|string|null $column): static
    {
        return $this->reorder($column, 'desc');
    }

    /**
     * Get an array with all orders with a given column removed.
     */
    protected function removeExistingOrdersFor(string $column): array
    {
        return (new Collection($this->orders))
            ->reject(fn ($order) => isset($order['column']) && $order['column'] === $column)
            ->values()
            ->all();
    }

    /**
     * Add a "union" statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<*>  $query
     */
    public function union(Closure|self|EloquentBuilder $query, bool $all = false): static
    {
        if ($query instanceof Closure) {
            $query($query = $this->newQuery());
        }

        $this->unions[] = compact('query', 'all');

        $this->addBinding($query->getBindings(), 'union');

        return $this;
    }

    /**
     * Add a "union all" statement to the query.
     *
     * @param  Closure|self|EloquentBuilder<*>  $query
     */
    public function unionAll(Closure|self|EloquentBuilder $query): static
    {
        return $this->union($query, true);
    }

    /**
     * Lock the selected rows in the table.
     */
    public function lock(string|bool $value = true): static
    {
        $this->lock = $value;
        $this->useWritePdo();

        return $this;
    }

    /**
     * Lock the selected rows in the table for updating.
     */
    public function lockForUpdate(): static
    {
        return $this->lock(true);
    }

    /**
     * Share lock the selected rows in the table.
     */
    public function sharedLock(): static
    {
        return $this->lock(false);
    }

    /**
     * Register a closure to be invoked before the query is executed.
     */
    public function beforeQuery(callable $callback): static
    {
        $this->beforeQueryCallbacks[] = $callback;

        return $this;
    }

    /**
     * Invoke the "before query" modification callbacks.
     */
    public function applyBeforeQueryCallbacks(): void
    {
        foreach ($this->beforeQueryCallbacks as $callback) {
            $callback($this);
        }

        $this->beforeQueryCallbacks = [];
    }

    /**
     * Register a closure to be invoked after the query is executed.
     */
    public function afterQuery(Closure $callback): static
    {
        $this->afterQueryCallbacks[] = $callback;

        return $this;
    }

    /**
     * Invoke the "after query" modification callbacks.
     */
    public function applyAfterQueryCallbacks(Collection $result): Collection
    {
        foreach ($this->afterQueryCallbacks as $afterQueryCallback) {
            $result = $afterQueryCallback($result) ?: $result;
        }

        return $result;
    }

    /**
     * Get the SQL representation of the query.
     */
    public function toSql(): string
    {
        $this->applyBeforeQueryCallbacks();

        return $this->grammar->compileSelect($this);
    }

    /**
     * Get the raw SQL representation of the query with embedded bindings.
     */
    public function toRawSql(): string
    {
        return $this->grammar->substituteBindingsIntoRawSql(
            $this->toSql(),
            $this->connection->prepareBindings($this->getBindings())
        );
    }

    /**
     * Execute a query for a single record by ID.
     *
     * @param array<ExpressionContract|string>|ExpressionContract|string $columns
     */
    public function find(int|string $id, ExpressionContract|array|string $columns = ['*']): object|array|null
    {
        return $this->where('id', '=', $id)->first($columns);
    }

    /**
     * Execute a query for a single record by ID or call a callback.
     *
     * @template TValue
     *
     * @param array<ExpressionContract|string>|(Closure(): TValue)|ExpressionContract|string $columns
     * @param null|(Closure(): TValue) $callback
     * @return stdClass|TValue
     */
    public function findOr(mixed $id, Closure|ExpressionContract|array|string $columns = ['*'], ?Closure $callback = null): mixed
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (! is_null($data = $this->find($id, $columns))) {
            return $data;
        }

        return $callback();
    }

    /**
     * Get a single column's value from the first result of a query.
     */
    public function value(string $column): mixed
    {
        $result = (array) $this->first([$column]);

        return count($result) > 0 ? Arr::first($result) : null;
    }

    /**
     * Get a single expression value from the first result of a query.
     */
    public function rawValue(string $expression, array $bindings = []): mixed
    {
        $result = (array) $this->selectRaw($expression, $bindings)->first();

        return count($result) > 0 ? Arr::first($result) : null;
    }

    /**
     * Get a single column's value from the first result of a query if it's the sole matching record.
     *
     * @throws \Hypervel\Database\RecordsNotFoundException
     * @throws \Hypervel\Database\MultipleRecordsFoundException
     */
    public function soleValue(string $column): mixed
    {
        $result = (array) $this->sole([$column]);

        return Arr::first($result);
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array<ExpressionContract|string>|ExpressionContract|string $columns
     * @return Collection<int, stdClass>
     */
    public function get(ExpressionContract|array|string $columns = ['*']): Collection
    {
        $items = new Collection($this->onceWithColumns(Arr::wrap($columns), function () {
            return $this->processor->processSelect($this, $this->runSelect());
        }));

        return $this->applyAfterQueryCallbacks(
            isset($this->groupLimit) ? $this->withoutGroupLimitKeys($items) : $items
        );
    }

    /**
     * Run the query as a "select" statement against the connection.
     */
    protected function runSelect(): array
    {
        return $this->connection->select(
            $this->toSql(),
            $this->getBindings(),
            ! $this->useWritePdo,
            $this->fetchUsing
        );
    }

    /**
     * Remove the group limit keys from the results in the collection.
     */
    protected function withoutGroupLimitKeys(Collection $items): Collection
    {
        $keysToRemove = ['laravel_row'];

        if (is_string($this->groupLimit['column'])) {
            $column = last(explode('.', $this->groupLimit['column']));

            $keysToRemove[] = '@laravel_group := ' . $this->grammar->wrap($column);
            $keysToRemove[] = '@laravel_group := ' . $this->grammar->wrap('pivot_' . $column);
        }

        $items->each(function ($item) use ($keysToRemove) {
            foreach ($keysToRemove as $key) {
                unset($item->{$key});
            }
        });

        return $items;
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param array<ExpressionContract|string>|ExpressionContract|string $columns
     */
    public function paginate(
        int|Closure $perPage = 15,
        ExpressionContract|array|string $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
        Closure|int|null $total = null,
    ): LengthAwarePaginator {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $total = value($total) ?? $this->getCountForPagination();

        $perPage = value($perPage, $total);

        $results = $total ? $this->forPage($page, $perPage)->get($columns) : new Collection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Get a paginator only supporting simple next and previous links.
     *
     * This is more efficient on larger data-sets, etc.
     *
     * @param array<ExpressionContract|string>|ExpressionContract|string $columns
     */
    public function simplePaginate(
        int $perPage = 15,
        ExpressionContract|array|string $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null,
    ): PaginatorContract {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $this->offset(($page - 1) * $perPage)->limit($perPage + 1);

        return $this->simplePaginator($this->get($columns), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Get a paginator only supporting simple next and previous links.
     *
     * This is more efficient on larger data-sets, etc.
     *
     * @param array<ExpressionContract|string>|ExpressionContract|string $columns
     */
    public function cursorPaginate(
        ?int $perPage = 15,
        ExpressionContract|array|string $columns = ['*'],
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ): CursorPaginatorContract {
        return $this->paginateUsingCursor($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Ensure the proper order by required for cursor pagination.
     */
    protected function ensureOrderForCursorPagination(bool $shouldReverse = false): Collection
    {
        if (empty($this->orders) && empty($this->unionOrders)) {
            $this->enforceOrderBy();
        }

        $reverseDirection = function ($order) {
            if (! isset($order['direction'])) {
                return $order;
            }

            $order['direction'] = $order['direction'] === 'asc' ? 'desc' : 'asc';

            return $order;
        };

        if ($shouldReverse) {
            $this->orders = (new Collection($this->orders))->map($reverseDirection)->toArray();
            $this->unionOrders = (new Collection($this->unionOrders))->map($reverseDirection)->toArray();
        }

        $orders = ! empty($this->unionOrders) ? $this->unionOrders : $this->orders;

        return (new Collection($orders))
            ->filter(fn ($order) => Arr::has($order, 'direction'))
            ->values();
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * @param array<ExpressionContract|string> $columns
     * @return int<0, max>
     */
    public function getCountForPagination(array $columns = ['*']): int
    {
        $results = $this->runPaginationCountQuery($columns);

        // Once we have run the pagination count query, we will get the resulting count and
        // take into account what type of query it was. When there is a group by we will
        // just return the count of the entire results set since that will be correct.
        if (! isset($results[0])) {
            return 0;
        }
        if (is_object($results[0])) {
            return (int) $results[0]->aggregate;
        }

        return (int) array_change_key_case((array) $results[0])['aggregate'];
    }

    /**
     * Run a pagination count query.
     *
     * @param array<ExpressionContract|string> $columns
     * @return array<mixed>
     */
    protected function runPaginationCountQuery(array $columns = ['*']): array
    {
        if ($this->groups || $this->havings) {
            $clone = $this->cloneForPaginationCount();

            if (is_null($clone->columns) && ! empty($this->joins)) {
                $clone->select($this->from . '.*');
            }

            return $this->newQuery()
                ->from(new Expression('(' . $clone->toSql() . ') as ' . $this->grammar->wrap('aggregate_table')))
                ->mergeBindings($clone)
                ->setAggregate('count', $this->withoutSelectAliases($columns))
                ->get()->all();
        }

        $without = $this->unions ? ['unionOrders', 'unionLimit', 'unionOffset'] : ['columns', 'orders', 'limit', 'offset'];

        return $this->cloneWithout($without)
            ->cloneWithoutBindings($this->unions ? ['unionOrder'] : ['select', 'order'])
            ->setAggregate('count', $this->withoutSelectAliases($columns))
            ->get()->all();
    }

    /**
     * Clone the existing query instance for usage in a pagination subquery.
     */
    protected function cloneForPaginationCount(): self
    {
        return $this->cloneWithout(['orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['order']);
    }

    /**
     * Remove the column aliases since they will break count queries.
     *
     * @param array<ExpressionContract|string> $columns
     * @return array<ExpressionContract|string>
     */
    protected function withoutSelectAliases(array $columns): array
    {
        return array_map(function ($column) {
            return is_string($column) && ($aliasPosition = stripos($column, ' as ')) !== false
                ? substr($column, 0, $aliasPosition)
                : $column;
        }, $columns);
    }

    /**
     * Get a lazy collection for the given query.
     *
     * @return LazyCollection<int, stdClass>
     */
    public function cursor(): LazyCollection
    {
        if (is_null($this->columns)) {
            $this->columns = ['*'];
        }

        return (new LazyCollection(function () {
            yield from $this->connection->cursor(
                $this->toSql(),
                $this->getBindings(),
                ! $this->useWritePdo,
                $this->fetchUsing
            );
        }))->map(function ($item) {
            return $this->applyAfterQueryCallbacks(new Collection([$item]))->first();
        })->reject(fn ($item) => is_null($item));
    }

    /**
     * Throw an exception if the query doesn't have an orderBy clause.
     *
     * @throws RuntimeException
     */
    protected function enforceOrderBy(): void
    {
        if (empty($this->orders) && empty($this->unionOrders)) {
            throw new RuntimeException('You must specify an orderBy clause when using this function.');
        }
    }

    /**
     * Get a collection instance containing the values of a given column.
     *
     * @return Collection<array-key, mixed>
     */
    public function pluck(ExpressionContract|string $column, ?string $key = null): Collection
    {
        // First, we will need to select the results of the query accounting for the
        // given columns / key. Once we have the results, we will be able to take
        // the results and get the exact data that was requested for the query.
        $queryResult = $this->onceWithColumns(
            is_null($key) || $key === $column ? [$column] : [$column, $key],
            function () {
                return $this->processor->processSelect(
                    $this,
                    $this->runSelect()
                );
            }
        );

        if (empty($queryResult)) {
            return new Collection();
        }

        // If the columns are qualified with a table or have an alias, we cannot use
        // those directly in the "pluck" operations since the results from the DB
        // are only keyed by the column itself. We'll strip the table out here.
        $column = $this->stripTableForPluck($column);

        $key = $this->stripTableForPluck($key);

        return $this->applyAfterQueryCallbacks(
            is_array($queryResult[0])
                ? $this->pluckFromArrayColumn($queryResult, $column, $key)
                : $this->pluckFromObjectColumn($queryResult, $column, $key)
        );
    }

    /**
     * Strip off the table name or alias from a column identifier.
     */
    protected function stripTableForPluck(ExpressionContract|string|null $column): ?string
    {
        if (is_null($column)) {
            return $column;
        }

        $columnString = $column instanceof ExpressionContract
            ? $this->grammar->getValue($column)
            : $column;

        $separator = str_contains(strtolower($columnString), ' as ') ? ' as ' : '\.';

        return last(preg_split('~' . $separator . '~i', $columnString));
    }

    /**
     * Retrieve column values from rows represented as objects.
     */
    protected function pluckFromObjectColumn(array $queryResult, string $column, ?string $key): Collection
    {
        $results = [];

        if (is_null($key)) {
            foreach ($queryResult as $row) {
                $results[] = $row->{$column};
            }
        } else {
            foreach ($queryResult as $row) {
                $results[$row->{$key}] = $row->{$column};
            }
        }

        return new Collection($results);
    }

    /**
     * Retrieve column values from rows represented as arrays.
     */
    protected function pluckFromArrayColumn(array $queryResult, string $column, ?string $key): Collection
    {
        $results = [];

        if (is_null($key)) {
            foreach ($queryResult as $row) {
                $results[] = $row[$column];
            }
        } else {
            foreach ($queryResult as $row) {
                $results[$row[$key]] = $row[$column];
            }
        }

        return new Collection($results);
    }

    /**
     * Concatenate values of a given column as a string.
     */
    public function implode(string $column, string $glue = ''): string
    {
        return $this->pluck($column)->implode($glue);
    }

    /**
     * Determine if any rows exist for the current query.
     */
    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();

        $results = $this->connection->select(
            $this->grammar->compileExists($this),
            $this->getBindings(),
            ! $this->useWritePdo,
            $this->fetchUsing
        );

        // If the results have rows, we will get the row and see if the exists column is a
        // boolean true. If there are no results for this query we will return false as
        // there are no rows for this query at all, and we can return that info here.
        if (isset($results[0])) {
            $results = (array) $results[0];

            return (bool) $results['exists'];
        }

        return false;
    }

    /**
     * Determine if no rows exist for the current query.
     */
    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * Execute the given callback if no rows exist for the current query.
     */
    public function existsOr(Closure $callback): mixed
    {
        return $this->exists() ? true : $callback();
    }

    /**
     * Execute the given callback if rows exist for the current query.
     */
    public function doesntExistOr(Closure $callback): mixed
    {
        return $this->doesntExist() ? true : $callback();
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @return int<0, max>
     */
    public function count(ExpressionContract|string $columns = '*'): int
    {
        return (int) $this->aggregate(__FUNCTION__, Arr::wrap($columns));
    }

    /**
     * Retrieve the minimum value of a given column.
     */
    public function min(ExpressionContract|string $column): mixed
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the maximum value of a given column.
     */
    public function max(ExpressionContract|string $column): mixed
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the sum of the values of a given column.
     */
    public function sum(ExpressionContract|string $column): mixed
    {
        $result = $this->aggregate(__FUNCTION__, [$column]);

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column.
     */
    public function avg(ExpressionContract|string $column): mixed
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Alias for the "avg" method.
     */
    public function average(ExpressionContract|string $column): mixed
    {
        return $this->avg($column);
    }

    /**
     * Execute an aggregate function on the database.
     */
    public function aggregate(string $function, array $columns = ['*']): mixed
    {
        $results = $this->cloneWithout($this->unions || $this->havings ? [] : ['columns'])
            ->cloneWithoutBindings($this->unions || $this->havings ? [] : ['select'])
            ->setAggregate($function, $columns)
            ->get($columns);

        if (! $results->isEmpty()) {
            return array_change_key_case((array) $results[0])['aggregate'];
        }

        return null;
    }

    /**
     * Execute a numeric aggregate function on the database.
     */
    public function numericAggregate(string $function, array $columns = ['*']): float|int
    {
        $result = $this->aggregate($function, $columns);

        // If there is no result, we can obviously just return 0 here. Next, we will check
        // if the result is an integer or float. If it is already one of these two data
        // types we can just return the result as-is, otherwise we will convert this.
        if (! $result) {
            return 0;
        }

        if (is_int($result) || is_float($result)) {
            return $result;
        }

        // If the result doesn't contain a decimal place, we will assume it is an int then
        // cast it to one. When it does we will cast it to a float since it needs to be
        // cast to the expected data type for the developers out of pure convenience.
        return ! str_contains((string) $result, '.')
            ? (int) $result
            : (float) $result;
    }

    /**
     * Set the aggregate property without running the query.
     *
     * @param array<ExpressionContract|string> $columns
     */
    protected function setAggregate(string $function, array $columns): static
    {
        $this->aggregate = compact('function', 'columns');

        if (empty($this->groups)) {
            $this->orders = null;

            $this->bindings['order'] = [];
        }

        return $this;
    }

    /**
     * Execute the given callback while selecting the given columns.
     *
     * After running the callback, the columns are reset to the original value.
     *
     * @template TResult
     *
     * @param array<ExpressionContract|string> $columns
     * @param callable(): TResult $callback
     * @return TResult
     */
    protected function onceWithColumns(array $columns, callable $callback): mixed
    {
        $original = $this->columns;

        if (is_null($original)) {
            $this->columns = $columns;
        }

        $result = $callback();

        $this->columns = $original;

        return $result;
    }

    /**
     * Insert new records into the database.
     */
    public function insert(array $values): bool
    {
        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient when building these
        // inserts statements by verifying these elements are actually an array.
        if (empty($values)) {
            return true;
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        }

        // Here, we will sort the insert keys for every record so that each insert is
        // in the same order for the record. We need to make sure this is the case
        // so there are not any errors or problems when inserting these records.
        else {
            foreach ($values as $key => $value) {
                ksort($value);

                $values[$key] = $value;
            }
        }

        $this->applyBeforeQueryCallbacks();

        // Finally, we will run this query against the database connection and return
        // the results. We will need to also flatten these bindings before running
        // the query so they are all in one huge, flattened array for execution.
        return $this->connection->insert(
            $this->grammar->compileInsert($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1))
        );
    }

    /**
     * Insert new records into the database while ignoring errors.
     *
     * @return int<0, max>
     */
    public function insertOrIgnore(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);

                $values[$key] = $value;
            }
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->affectingStatement(
            $this->grammar->compileInsertOrIgnore($this, $values),
            $this->cleanBindings(Arr::flatten($values, 1))
        );
    }

    /**
     * Insert a new record and get the value of the primary key.
     */
    public function insertGetId(array $values, ?string $sequence = null): int|string
    {
        $this->applyBeforeQueryCallbacks();

        $sql = $this->grammar->compileInsertGetId($this, $values, $sequence);

        $values = $this->cleanBindings($values);

        return $this->processor->processInsertGetId($this, $sql, $values, $sequence);
    }

    /**
     * Insert new records into the table using a subquery.
     *
     * @param  Closure|self|EloquentBuilder<*>|string  $query
     */
    public function insertUsing(array $columns, Closure|self|EloquentBuilder|string $query): int
    {
        $this->applyBeforeQueryCallbacks();

        [$sql, $bindings] = $this->createSub($query);

        return $this->connection->affectingStatement(
            $this->grammar->compileInsertUsing($this, $columns, $sql),
            $this->cleanBindings($bindings)
        );
    }

    /**
     * Insert new records into the table using a subquery while ignoring errors.
     *
     * @param  Closure|self|EloquentBuilder<*>|string  $query
     */
    public function insertOrIgnoreUsing(array $columns, Closure|self|EloquentBuilder|string $query): int
    {
        $this->applyBeforeQueryCallbacks();

        [$sql, $bindings] = $this->createSub($query);

        return $this->connection->affectingStatement(
            $this->grammar->compileInsertOrIgnoreUsing($this, $columns, $sql),
            $this->cleanBindings($bindings)
        );
    }

    /**
     * Update records in the database.
     *
     * @return int<0, max>
     */
    public function update(array $values): int
    {
        $this->applyBeforeQueryCallbacks();

        $values = (new Collection($values))->map(function ($value) {
            if (! $value instanceof Builder) {
                return ['value' => $value, 'bindings' => match (true) {
                    $value instanceof Collection => $value->all(),
                    $value instanceof UnitEnum => enum_value($value),
                    default => $value,
                }];
            }

            [$query, $bindings] = $this->parseSub($value);

            return ['value' => new Expression("({$query})"), 'bindings' => fn () => $bindings];
        });

        $sql = $this->grammar->compileUpdate($this, $values->map(fn ($value) => $value['value'])->all());

        return $this->connection->update($sql, $this->cleanBindings(
            $this->grammar->prepareBindingsForUpdate($this->bindings, $values->map(fn ($value) => $value['bindings'])->all())
        ));
    }

    /**
     * Update records in a PostgreSQL database using the update from syntax.
     */
    public function updateFrom(array $values): int
    {
        if (! method_exists($this->grammar, 'compileUpdateFrom')) {
            throw new LogicException('This database engine does not support the updateFrom method.');
        }

        $this->applyBeforeQueryCallbacks();

        // @phpstan-ignore method.notFound (driver-specific method checked by method_exists above)
        $sql = $this->grammar->compileUpdateFrom($this, $values);

        return $this->connection->update($sql, $this->cleanBindings(
            // @phpstan-ignore method.notFound (driver-specific method checked by method_exists above)
            $this->grammar->prepareBindingsForUpdateFrom($this->bindings, $values)
        ));
    }

    /**
     * Insert or update a record matching the attributes, and fill it with values.
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        $exists = $this->where($attributes)->exists();

        if ($values instanceof Closure) {
            $values = $values($exists);
        }

        if (! $exists) {
            return $this->insert(array_merge($attributes, $values));
        }

        if (empty($values)) {
            return true;
        }

        return (bool) $this->limit(1)->update($values);
    }

    /**
     * Insert new records or update the existing ones.
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if (empty($values)) {
            return 0;
        }
        if ($update === []) {
            return (int) $this->insert($values);
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);

                $values[$key] = $value;
            }
        }

        if (is_null($update)) {
            $update = array_keys(Arr::first($values));
        }

        $this->applyBeforeQueryCallbacks();

        $bindings = $this->cleanBindings(array_merge(
            Arr::flatten($values, 1),
            (new Collection($update))
                ->reject(fn ($value, $key) => is_int($key))
                ->all()
        ));

        return $this->connection->affectingStatement(
            $this->grammar->compileUpsert($this, $values, (array) $uniqueBy, $update),
            $bindings
        );
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @return int<0, max>
     *
     * @throws InvalidArgumentException
     */
    public function increment(string $column, mixed $amount = 1, array $extra = []): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Non-numeric value passed to increment method.');
        }

        return $this->incrementEach([$column => $amount], $extra);
    }

    /**
     * Increment the given column's values by the given amounts.
     *
     * @param array<string, float|int|numeric-string> $columns
     * @param array<string, mixed> $extra
     * @return int<0, max>
     *
     * @throws InvalidArgumentException
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        foreach ($columns as $column => $amount) {
            // @phpstan-ignore function.alreadyNarrowedType (runtime validation for user input)
            if (! is_numeric($amount)) {
                throw new InvalidArgumentException("Non-numeric value passed as increment amount for column: '{$column}'.");
            }
            // @phpstan-ignore function.alreadyNarrowedType (runtime validation - user could pass indexed array)
            if (! is_string($column)) {
                throw new InvalidArgumentException('Non-associative array passed to incrementEach method.');
            }

            $columns[$column] = $this->raw("{$this->grammar->wrap($column)} + {$amount}");
        }

        return $this->update(array_merge($columns, $extra));
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @return int<0, max>
     *
     * @throws InvalidArgumentException
     */
    public function decrement(string $column, mixed $amount = 1, array $extra = []): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Non-numeric value passed to decrement method.');
        }

        return $this->decrementEach([$column => $amount], $extra);
    }

    /**
     * Decrement the given column's values by the given amounts.
     *
     * @param array<string, float|int|numeric-string> $columns
     * @param array<string, mixed> $extra
     * @return int<0, max>
     *
     * @throws InvalidArgumentException
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        foreach ($columns as $column => $amount) {
            $columns[$column] = $this->raw("{$this->grammar->wrap($column)} - {$amount}");
        }

        return $this->update(array_merge($columns, $extra));
    }

    /**
     * Delete records from the database.
     */
    public function delete(mixed $id = null): int
    {
        // If an ID is passed to the method, we will set the where clause to check the
        // ID to let developers to simply and quickly remove a single row from this
        // database without manually specifying the "where" clauses on the query.
        if (! is_null($id)) {
            $this->where($this->from . '.id', '=', $id);
        }

        $this->applyBeforeQueryCallbacks();

        return $this->connection->delete(
            $this->grammar->compileDelete($this),
            $this->cleanBindings(
                $this->grammar->prepareBindingsForDelete($this->bindings)
            )
        );
    }

    /**
     * Run a "truncate" statement on the table.
     */
    public function truncate(): void
    {
        $this->applyBeforeQueryCallbacks();

        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            $this->connection->statement($sql, $bindings);
        }
    }

    /**
     * Get a new instance of the query builder.
     */
    public function newQuery(): self
    {
        return new static($this->connection, $this->grammar, $this->processor);
    }

    /**
     * Create a new query instance for a sub-query.
     */
    protected function forSubQuery(): self
    {
        return $this->newQuery();
    }

    /**
     * Get all of the query builder's columns in a text-only array with all expressions evaluated.
     *
     * @return list<string>
     */
    public function getColumns(): array
    {
        return ! is_null($this->columns)
            ? array_map(fn ($column) => $this->grammar->getValue($column), $this->columns)
            : [];
    }

    /**
     * Create a raw database expression.
     */
    public function raw(mixed $value): ExpressionContract
    {
        return $this->connection->raw($value);
    }

    /**
     * Get the query builder instances that are used in the union of the query.
     */
    protected function getUnionBuilders(): Collection
    {
        return isset($this->unions)
            ? (new Collection($this->unions))->pluck('query')
            : new Collection();
    }

    /**
     * Get the "limit" value for the query or null if it's not set.
     */
    public function getLimit(): ?int
    {
        $value = $this->unions ? $this->unionLimit : $this->limit;

        return ! is_null($value) ? (int) $value : null;
    }

    /**
     * Get the "offset" value for the query or null if it's not set.
     */
    public function getOffset(): ?int
    {
        $value = $this->unions ? $this->unionOffset : $this->offset;

        return ! is_null($value) ? (int) $value : null;
    }

    /**
     * Get the current query value bindings in a flattened array.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return Arr::flatten($this->bindings);
    }

    /**
     * Get the raw array of bindings.
     *
     * @return array{
     *      select: list<mixed>,
     *      from: list<mixed>,
     *      join: list<mixed>,
     *      where: list<mixed>,
     *      groupBy: list<mixed>,
     *      having: list<mixed>,
     *      order: list<mixed>,
     *      union: list<mixed>,
     *      unionOrder: list<mixed>,
     * }
     */
    public function getRawBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Set the bindings on the query builder.
     *
     * @param list<mixed> $bindings
     * @param "from"|"groupBy"|"having"|"join"|"order"|"select"|"union"|"unionOrder"|"where" $type
     *
     * @throws InvalidArgumentException
     */
    public function setBindings(array $bindings, string $type = 'where'): static
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        $this->bindings[$type] = $bindings;

        return $this;
    }

    /**
     * Add a binding to the query.
     *
     * @param "from"|"groupBy"|"having"|"join"|"order"|"select"|"union"|"unionOrder"|"where" $type
     *
     * @throws InvalidArgumentException
     */
    public function addBinding(mixed $value, string $type = 'where'): static
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_map(
                $this->castBinding(...),
                array_merge($this->bindings[$type], $value),
            ));
        } else {
            $this->bindings[$type][] = $this->castBinding($value);
        }

        return $this;
    }

    /**
     * Cast the given binding value.
     */
    public function castBinding(mixed $value): mixed
    {
        if ($value instanceof UnitEnum) {
            return enum_value($value);
        }

        return $value;
    }

    /**
     * Merge an array of bindings into our bindings.
     */
    public function mergeBindings(self $query): static
    {
        $this->bindings = array_merge_recursive($this->bindings, $query->bindings);

        return $this;
    }

    /**
     * Remove all of the expressions from a list of bindings.
     *
     * @param array<mixed> $bindings
     * @return list<mixed>
     */
    public function cleanBindings(array $bindings): array
    {
        return (new Collection($bindings))
            ->reject(function ($binding) {
                return $binding instanceof ExpressionContract;
            })
            ->map($this->castBinding(...))
            ->values()
            ->all();
    }

    /**
     * Get a scalar type value from an unknown type of input.
     */
    protected function flattenValue(mixed $value): mixed
    {
        return is_array($value) ? head(Arr::flatten($value)) : $value;
    }

    /**
     * Get the default key name of the table.
     */
    protected function defaultKeyName(): string
    {
        return 'id';
    }

    /**
     * Get the database connection instance.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Ensure the database connection supports vector queries.
     */
    protected function ensureConnectionSupportsVectors(): void
    {
        if (! $this->connection instanceof PostgresConnection) {
            throw new RuntimeException('Vector distance queries are only supported by Postgres.');
        }
    }

    /**
     * Get the database query processor instance.
     */
    public function getProcessor(): Processor
    {
        return $this->processor;
    }

    /**
     * Get the query grammar instance.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Use the "write" PDO connection when executing the query.
     */
    public function useWritePdo(): static
    {
        $this->useWritePdo = true;

        return $this;
    }

    /**
     * Set the PDO fetch mode arguments for the query.
     */
    public function fetchUsing(mixed ...$fetchUsing): static
    {
        $this->fetchUsing = $fetchUsing;

        return $this;
    }

    /**
     * Determine if the value is a query builder instance or a Closure.
     */
    protected function isQueryable(mixed $value): bool
    {
        return $value instanceof self
               || $value instanceof EloquentBuilder
               || $value instanceof Relation
               || $value instanceof Closure;
    }

    /**
     * Clone the query.
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Clone the query without the given properties.
     */
    public function cloneWithout(array $properties): static
    {
        return tap($this->clone(), function ($clone) use ($properties) {
            foreach ($properties as $property) {
                $clone->{$property} = is_array($clone->{$property}) ? [] : null;
            }
        });
    }

    /**
     * Clone the query without the given bindings.
     */
    public function cloneWithoutBindings(array $except): static
    {
        return tap($this->clone(), function ($clone) use ($except) {
            foreach ($except as $type) {
                $clone->bindings[$type] = [];
            }
        });
    }

    /**
     * Dump the current SQL and bindings.
     */
    public function dump(mixed ...$args): static
    {
        dump(
            $this->toSql(),
            $this->getBindings(),
            ...$args,
        );

        return $this;
    }

    /**
     * Dump the raw current SQL with embedded bindings.
     */
    public function dumpRawSql(): static
    {
        dump($this->toRawSql());

        return $this;
    }

    /**
     * Die and dump the current SQL and bindings.
     */
    public function dd(): never
    {
        dd($this->toSql(), $this->getBindings());
    }

    /**
     * Die and dump the current SQL with embedded bindings.
     */
    public function ddRawSql(): never
    {
        dd($this->toRawSql());
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (str_starts_with($method, 'where')) {
            return $this->dynamicWhere($method, $parameters);
        }

        static::throwBadMethodCallException($method);
    }
}
