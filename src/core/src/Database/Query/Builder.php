<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

use Closure;
use Hyperf\Database\Query\Builder as BaseBuilder;
use Hyperf\Database\Query\Expression;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\LazyCollection;

/**
 * @method $this from(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $table, string|null $as = null)
 * @method $this fromSub(\Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder|string $query, string $as)
 * @method $this selectSub(\Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder|string $query, string $as)
 * @method $this joinSub(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $query, string $as, \Closure|string $first, string|null $operator = null, string|null $second = null, string $type = 'inner', bool $where = false)
 * @method $this leftJoinSub(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $query, string $as, \Closure|string $first, string|null $operator = null, string|null $second = null)
 * @method $this rightJoinSub(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $query, string $as, \Closure|string $first, string|null $operator = null, string|null $second = null)
 * @method $this joinLateral(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $query, string $as, string $type = 'inner')
 * @method $this leftJoinLateral(\Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder|string $query, string $as)
 * @method $this crossJoinSub(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $query, string|null $as = null)
 * @method $this whereExists(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder $callback, string $boolean = 'and', bool $not = false)
 * @method $this orWhereExists(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder $callback, bool $not = false)
 * @method $this whereNotExists(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder $callback, string $boolean = 'and')
 * @method $this orWhereNotExists(\Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder $callback)
 * @method $this orderBy(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder|string $column, string $direction = 'asc')
 * @method $this orderByDesc(\Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder|string $column)
 * @method $this union(\Closure|\Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder $query, bool $all = false)
 * @method $this unionAll(\Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder $query)
 * @method int insertUsing(array $columns, \Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder|string $query)
 * @method int insertOrIgnoreUsing(array $columns, \Closure|\Hypervel\Database\Eloquent\Builder|\Hypervel\Database\Query\Builder|string $query)
 * @method null|object find(mixed $id, array $columns = ['*'])
 * @method null|object first(array|string $columns = ['*'])
 * @method bool chunk(int $count, callable(\Hypervel\Support\Collection<int, object>, int): mixed $callback, array $columns = ['*'])
 * @method bool chunkById(int $count, callable(\Hypervel\Support\Collection<int, object>, int): mixed $callback, string|null $column = null, string|null $alias = null)
 * @method bool chunkByIdDesc(int $count, callable(\Hypervel\Support\Collection<int, object>, int): mixed $callback, string|null $column = null, string|null $alias = null)
 * @method bool each(callable(object, int): mixed $callback, int $count = 1000)
 * @method bool eachById(callable(object, int): mixed $callback, int $count = 1000, string|null $column = null, string|null $alias = null)
 */
class Builder extends BaseBuilder
{
    /**
     * The callbacks that should be invoked before the query is executed.
     *
     * @var array<int, callable>
     */
    public array $beforeQueryCallbacks = [];

    /**
     * The callbacks that should be invoked after retrieving data from the database.
     *
     * @var array<int, Closure>
     */
    protected array $afterQueryCallbacks = [];

    /**
     * @template TValue
     *
     * @param mixed $id
     * @param array<\Hyperf\Database\Query\Expression|string>|(Closure(): TValue)|\Hyperf\Database\Query\Expression|string $columns
     * @param null|(Closure(): TValue) $callback
     * @return object|TValue
     */
    public function findOr($id, $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;
            $columns = ['*'];
        }

        if (! is_null($record = $this->find($id, $columns))) {
            return $record;
        }

        return $callback();
    }

    /**
     * @return \Hypervel\Support\LazyCollection<int, object>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize) {
            yield from parent::lazy($chunkSize);
        });
    }

    /**
     * @return \Hypervel\Support\LazyCollection<int, object>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize, $column, $alias) {
            yield from parent::lazyById($chunkSize, $column, $alias);
        });
    }

    /**
     * @return \Hypervel\Support\LazyCollection<int, object>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize, $column, $alias) {
            yield from parent::lazyByIdDesc($chunkSize, $column, $alias);
        });
    }

    /**
     * @template TReturn
     *
     * @param callable(object): TReturn $callback
     * @return \Hypervel\Support\Collection<int, TReturn>
     */
    public function chunkMap(callable $callback, int $count = 1000): BaseCollection
    {
        return new BaseCollection(parent::chunkMap($callback, $count)->all());
    }

    /**
     * @param array<array-key, string>|string $column
     * @param null|string $key
     * @return \Hypervel\Support\Collection<array-key, mixed>
     */
    public function pluck($column, $key = null)
    {
        return new BaseCollection(parent::pluck($column, $key)->all());
    }

    /**
     * Add a "where not" clause to the query.
     */
    public function whereNot(
        Closure|string|array|Expression $column,
        mixed $operator = null,
        mixed $value = null,
        string $boolean = 'and',
    ): static {
        if (is_array($column)) {
            $this->whereNested(function ($query) use ($column, $operator, $value, $boolean) {
                $query->where($column, $operator, $value, $boolean);
            }, $boolean.' not');

            return $this;
        }

        return $this->where($column, $operator, $value, $boolean.' not');
    }

    /**
     * Add an "or where not" clause to the query.
     */
    public function orWhereNot(
        Closure|string|array|Expression $column,
        mixed $operator = null,
        mixed $value = null,
    ): static {
        return $this->whereNot($column, $operator, $value, 'or');
    }

    /**
     * Add a "where like" clause to the query.
     */
    public function whereLike(
        Expression|string $column,
        string $value,
        bool $caseSensitive = false,
        string $boolean = 'and',
        bool $not = false,
    ): static {
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
    public function orWhereLike(Expression|string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereLike($column, $value, $caseSensitive, 'or', false);
    }

    /**
     * Add a "where not like" clause to the query.
     */
    public function whereNotLike(
        Expression|string $column,
        string $value,
        bool $caseSensitive = false,
        string $boolean = 'and',
    ): static {
        return $this->whereLike($column, $value, $caseSensitive, $boolean, true);
    }

    /**
     * Add an "or where not like" clause to the query.
     */
    public function orWhereNotLike(Expression|string $column, string $value, bool $caseSensitive = false): static
    {
        return $this->whereNotLike($column, $value, $caseSensitive, 'or');
    }

    /**
     * Add a "where in raw" clause for integer values to the query.
     *
     * @param \Hyperf\Contract\Arrayable<array-key, int>|array<int> $values
     */
    public function orWhereIntegerInRaw(string $column, $values): static
    {
        return $this->whereIntegerInRaw($column, $values, 'or');
    }

    /**
     * Add a "where not in raw" clause for integer values to the query.
     *
     * @param \Hyperf\Contract\Arrayable<array-key, int>|array<int> $values
     */
    public function orWhereIntegerNotInRaw(string $column, $values): static
    {
        return $this->whereIntegerNotInRaw($column, $values, 'or');
    }

    /**
     * Add a "where between columns" clause to the query.
     *
     * @param array{0: Expression|string, 1: Expression|string} $values
     */
    public function whereBetweenColumns(
        Expression|string $column,
        array $values,
        string $boolean = 'and',
        bool $not = false,
    ): static {
        $type = 'betweenColumns';

        $this->wheres[] = compact('type', 'column', 'values', 'boolean', 'not');

        return $this;
    }

    /**
     * Add an "or where between columns" clause to the query.
     *
     * @param array{0: Expression|string, 1: Expression|string} $values
     */
    public function orWhereBetweenColumns(Expression|string $column, array $values): static
    {
        return $this->whereBetweenColumns($column, $values, 'or');
    }

    /**
     * Add a "where not between columns" clause to the query.
     *
     * @param array{0: Expression|string, 1: Expression|string} $values
     */
    public function whereNotBetweenColumns(
        Expression|string $column,
        array $values,
        string $boolean = 'and',
    ): static {
        return $this->whereBetweenColumns($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not between columns" clause to the query.
     *
     * @param array{0: Expression|string, 1: Expression|string} $values
     */
    public function orWhereNotBetweenColumns(Expression|string $column, array $values): static
    {
        return $this->whereNotBetweenColumns($column, $values, 'or');
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
     * Add a "having null" clause to the query.
     *
     * @param array<int, string>|string $columns
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
     *
     * @param array<int, string>|string $columns
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
     * Add a "having not between" clause to the query.
     *
     * @param array<int, mixed> $values
     */
    public function havingNotBetween(string $column, array $values, string $boolean = 'and'): static
    {
        $this->havingBetween($column, $values, $boolean, true);

        return $this;
    }

    /**
     * Add an "or having between" clause to the query.
     *
     * @param array<int, mixed> $values
     */
    public function orHavingBetween(string $column, array $values): static
    {
        $this->havingBetween($column, $values, 'or');

        return $this;
    }

    /**
     * Add an "or having not between" clause to the query.
     *
     * @param array<int, mixed> $values
     */
    public function orHavingNotBetween(string $column, array $values): static
    {
        $this->havingBetween($column, $values, 'or', true);

        return $this;
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
    public function addNestedHavingQuery(BaseBuilder $query, string $boolean = 'and'): static
    {
        if (count($query->havings)) {
            $type = 'Nested';

            $this->havings[] = compact('type', 'query', 'boolean');

            $this->addBinding($query->getRawBindings()['having'], 'having');
        }

        return $this;
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
    public function applyAfterQueryCallbacks(mixed $result): mixed
    {
        foreach ($this->afterQueryCallbacks as $callback) {
            $result = $callback($result) ?: $result;
        }

        return $result;
    }

    /**
     * Get the "limit" value for the query or null if it's not set.
     */
    public function getLimit(): ?int
    {
        /** @var int|null $value */
        $value = $this->unions ? $this->unionLimit : $this->limit;

        return isset($value) ? (int) $value : null;
    }

    /**
     * Get the "offset" value for the query or null if it's not set.
     */
    public function getOffset(): ?int
    {
        /** @var int|null $value */
        $value = $this->unions ? $this->unionOffset : $this->offset;

        return isset($value) ? (int) $value : null;
    }

    /**
     * Get a single column's value from the first result of a query using a raw expression.
     *
     * @param array<int, mixed> $bindings
     */
    public function rawValue(string $expression, array $bindings = []): mixed
    {
        $result = (array) $this->selectRaw($expression, $bindings)->first();

        return count($result) > 0 ? Arr::first($result) : null;
    }

    /**
     * Get a single column's value from the first result of a query if it's the sole matching record.
     *
     * @throws \Hyperf\Database\Exception\RecordsNotFoundException
     * @throws \Hyperf\Database\Exception\MultipleRecordsFoundException
     */
    public function soleValue(string $column): mixed
    {
        $result = (array) $this->sole([$column]);

        return Arr::first($result);
    }
}
