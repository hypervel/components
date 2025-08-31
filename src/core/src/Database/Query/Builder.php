<?php

declare(strict_types=1);

namespace Hypervel\Database\Query;

use Closure;
use Hyperf\Database\Query\Builder as BaseBuilder;
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
}
