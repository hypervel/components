<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\RecordNotFoundException;
use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;
use RuntimeException;

/**
 * @template TValue
 *
 * @mixin \Hypervel\Database\Query\Builder
 */
trait BuildsQueries
{
    use Conditionable;

    /**
     * Chunk the results of the query.
     *
     * @param callable(\Hypervel\Support\Collection<int, TValue>, int): mixed $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        $this->enforceOrderBy();

        $skip = $this->getOffset();
        $remaining = $this->getLimit();

        $page = 1;

        do {
            $offset = (($page - 1) * $count) + (int) $skip;

            $limit = is_null($remaining) ? $count : min($count, $remaining);

            if ($limit == 0) {
                break;
            }

            $results = $this->offset($offset)->limit($limit)->get();

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if (! is_null($remaining)) {
                $remaining = max($remaining - $countResults, 0);
            }

            // @phpstan-ignore argument.type (Eloquent hydrates to TModel, not stdClass)
            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            ++$page;
        } while ($countResults == $count);

        return true;
    }

    /**
     * Run a map over each item while chunking.
     *
     * @template TReturn
     *
     * @param callable(TValue): TReturn $callback
     * @return \Hypervel\Support\Collection<int, TReturn>
     */
    public function chunkMap(callable $callback, int $count = 1000): Collection
    {
        $collection = new Collection();

        $this->chunk($count, function ($items) use ($collection, $callback) {
            $items->each(function ($item) use ($collection, $callback) {
                $collection->push($callback($item));
            });
        });

        return $collection;
    }

    /**
     * Execute a callback over each item while chunking.
     *
     * @param callable(TValue, int): mixed $callback
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }
        });
    }

    /**
     * Chunk the results of a query by comparing IDs.
     *
     * @param callable(\Hypervel\Support\Collection<int, TValue>, int): mixed $callback
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     *
     * @param callable(\Hypervel\Support\Collection<int, TValue>, int): mixed $callback
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias, descending: true);
    }

    /**
     * Chunk the results of a query by comparing IDs in a given order.
     *
     * @param callable(\Hypervel\Support\Collection<int, TValue>, int): mixed $callback
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $descending = false): bool
    {
        $column ??= $this->defaultKeyName();
        $alias ??= $column;
        $lastId = null;
        $skip = $this->getOffset();
        $remaining = $this->getLimit();

        $page = 1;

        do {
            $clone = clone $this;

            if ($skip && $page > 1) {
                $clone->offset(0);
            }

            $limit = is_null($remaining) ? $count : min($count, $remaining);

            if ($limit == 0) {
                break;
            }

            // We'll execute the query for the given page and get the results. If there are
            // no results we can just break and return from here. When there are results
            // we will call the callback with the current chunk of these results here.
            if ($descending) {
                $results = $clone->forPageBeforeId($limit, $lastId, $column)->get();
            } else {
                $results = $clone->forPageAfterId($limit, $lastId, $column)->get();
            }

            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if (! is_null($remaining)) {
                $remaining = max($remaining - $countResults, 0);
            }

            // On each chunk result set, we will pass them to the callback and then let the
            // developer take care of everything within the callback, which allows us to
            // keep the memory low for spinning through large result sets for working.
            // @phpstan-ignore argument.type (Eloquent hydrates to TModel, not stdClass)
            if ($callback($results, $page) === false) {
                return false;
            }

            $lastId = data_get($results->last(), $alias);

            if ($lastId === null) {
                throw new RuntimeException("The chunkById operation was aborted because the [{$alias}] column is not present in the query result.");
            }

            unset($results);

            ++$page;
        } while ($countResults == $count);

        return true;
    }

    /**
     * Execute a callback over each item while chunking by ID.
     *
     * @param callable(TValue, int): mixed $callback
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        return $this->chunkById($count, function ($results, $page) use ($callback, $count) {
            foreach ($results as $key => $value) {
                if ($callback($value, (($page - 1) * $count) + $key) === false) {
                    return false;
                }
            }
        }, $column, $alias);
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @return \Hypervel\Support\LazyCollection<int, TValue>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $this->enforceOrderBy();

        return new LazyCollection(function () use ($chunkSize) {
            $page = 1;

            while (true) {
                $results = $this->forPage($page++, $chunkSize)->get();

                foreach ($results as $result) {
                    yield $result;
                }

                if ($results->count() < $chunkSize) {
                    return;
                }
            }
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     *
     * @return \Hypervel\Support\LazyCollection<int, TValue>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     *
     * @return \Hypervel\Support\LazyCollection<int, TValue>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias, true);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in a given order.
     *
     * @return \Hypervel\Support\LazyCollection<int, TValue>
     */
    protected function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, bool $descending = false): LazyCollection
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $column ??= $this->defaultKeyName();

        $alias ??= $column;

        return new LazyCollection(function () use ($chunkSize, $column, $alias, $descending) {
            $lastId = null;

            while (true) {
                $clone = clone $this;

                if ($descending) {
                    $results = $clone->forPageBeforeId($chunkSize, $lastId, $column)->get();
                } else {
                    $results = $clone->forPageAfterId($chunkSize, $lastId, $column)->get();
                }

                foreach ($results as $result) {
                    yield $result;
                }

                if ($results->count() < $chunkSize) {
                    return;
                }

                $lastId = $results->last()->{$alias};

                if ($lastId === null) {
                    throw new RuntimeException("The lazyById operation was aborted because the [{$alias}] column is not present in the query result.");
                }
            }
        });
    }

    /**
     * Execute the query and get the first result.
     *
     * @return null|TValue
     */
    public function first(array|string $columns = ['*'])
    {
        // @phpstan-ignore return.type (Eloquent hydrates to TModel, not stdClass)
        return $this->limit(1)->get($columns)->first();
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return TValue
     *
     * @throws \Hypervel\Database\RecordNotFoundException
     */
    public function firstOrFail(array|string $columns = ['*'], ?string $message = null)
    {
        if (! is_null($result = $this->first($columns))) {
            return $result;
        }

        throw new RecordNotFoundException($message ?: 'No record found for the given query.');
    }

    /**
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return TValue
     *
     * @throws \Hypervel\Database\RecordsNotFoundException
     * @throws \Hypervel\Database\MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*'])
    {
        $result = $this->limit(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw new RecordsNotFoundException();
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        // @phpstan-ignore return.type (Eloquent hydrates to TModel, not stdClass)
        return $result->first();
    }

    /**
     * Paginate the given query using a cursor paginator.
     *
     * @return \Hypervel\Contracts\Pagination\CursorPaginator
     */
    protected function paginateUsingCursor(int $perPage, array|string $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null)
    {
        if (! $cursor instanceof Cursor) {
            $cursor = is_string($cursor)
                ? Cursor::fromEncoded($cursor)
                : CursorPaginator::resolveCurrentCursor($cursorName, $cursor);
        }

        $orders = $this->ensureOrderForCursorPagination(! is_null($cursor) && $cursor->pointsToPreviousItems());

        if (! is_null($cursor)) {
            // Reset the union bindings so we can add the cursor where in the correct position...
            $this->setBindings([], 'union');

            $addCursorConditions = function (self $builder, $previousColumn, $originalColumn, $i) use (&$addCursorConditions, $cursor, $orders) {
                $unionBuilders = $builder->getUnionBuilders();

                if (! is_null($previousColumn)) {
                    $originalColumn ??= $this->getOriginalColumnNameForCursorPagination($this, $previousColumn);

                    $builder->where(
                        Str::contains($originalColumn, ['(', ')']) ? new Expression($originalColumn) : $originalColumn,
                        '=',
                        $cursor->parameter($previousColumn)
                    );

                    $unionBuilders->each(function ($unionBuilder) use ($previousColumn, $cursor) {
                        $unionBuilder->where(
                            $this->getOriginalColumnNameForCursorPagination($unionBuilder, $previousColumn),
                            '=',
                            $cursor->parameter($previousColumn)
                        );

                        $this->addBinding($unionBuilder->getRawBindings()['where'], 'union');
                    });
                }

                $builder->where(function (self $secondBuilder) use ($addCursorConditions, $cursor, $orders, $i, $unionBuilders) {
                    ['column' => $column, 'direction' => $direction] = $orders[$i];

                    $originalColumn = $this->getOriginalColumnNameForCursorPagination($this, $column);

                    $secondBuilder->where(
                        Str::contains($originalColumn, ['(', ')']) ? new Expression($originalColumn) : $originalColumn,
                        $direction === 'asc' ? '>' : '<',
                        $cursor->parameter($column)
                    );

                    if ($i < $orders->count() - 1) {
                        $secondBuilder->orWhere(function (self $thirdBuilder) use ($addCursorConditions, $column, $originalColumn, $i) {
                            $addCursorConditions($thirdBuilder, $column, $originalColumn, $i + 1);
                        });
                    }

                    $unionBuilders->each(function ($unionBuilder) use ($column, $direction, $cursor, $i, $orders, $addCursorConditions) {
                        $unionWheres = $unionBuilder->getRawBindings()['where'];

                        $originalColumn = $this->getOriginalColumnNameForCursorPagination($unionBuilder, $column);
                        $unionBuilder->where(function ($unionBuilder) use ($column, $direction, $cursor, $i, $orders, $addCursorConditions, $originalColumn, $unionWheres) {
                            $unionBuilder->where(
                                $originalColumn,
                                $direction === 'asc' ? '>' : '<',
                                $cursor->parameter($column)
                            );

                            if ($i < $orders->count() - 1) {
                                $unionBuilder->orWhere(function (self $fourthBuilder) use ($addCursorConditions, $column, $originalColumn, $i) {
                                    $addCursorConditions($fourthBuilder, $column, $originalColumn, $i + 1);
                                });
                            }

                            $this->addBinding($unionWheres, 'union');
                            $this->addBinding($unionBuilder->getRawBindings()['where'], 'union');
                        });
                    });
                });
            };

            $addCursorConditions($this, null, null, 0);
        }

        $this->limit($perPage + 1);

        return $this->cursorPaginator($this->get($columns), $perPage, $cursor, [
            'path' => Paginator::resolveCurrentPath(),
            'cursorName' => $cursorName,
            'parameters' => $orders->pluck('column')->toArray(),
        ]);
    }

    /**
     * Get the original column name of the given column, without any aliasing.
     *
     * @param  \Hypervel\Database\Query\Builder|\Hypervel\Database\Eloquent\Builder<*>  $builder
     */
    protected function getOriginalColumnNameForCursorPagination(\Hypervel\Database\Query\Builder|Builder $builder, string $parameter): string
    {
        $columns = $builder instanceof Builder ? $builder->getQuery()->getColumns() : $builder->getColumns();

        foreach ($columns as $column) {
            if (($position = strripos($column, ' as ')) !== false) {
                $original = substr($column, 0, $position);

                $alias = substr($column, $position + 4);

                if ($parameter === $alias || $builder->getGrammar()->wrap($parameter) === $alias) {
                    return $original;
                }
            }
        }

        return $parameter;
    }

    /**
     * Create a new length-aware paginator instance.
     */
    protected function paginator(Collection $items, int $total, int $perPage, int $currentPage, array $options): LengthAwarePaginator
    {
        return Container::getInstance()->makeWith(LengthAwarePaginator::class, compact(
            'items',
            'total',
            'perPage',
            'currentPage',
            'options'
        ));
    }

    /**
     * Create a new simple paginator instance.
     */
    protected function simplePaginator(Collection $items, int $perPage, int $currentPage, array $options): Paginator
    {
        return Container::getInstance()->makeWith(Paginator::class, compact(
            'items',
            'perPage',
            'currentPage',
            'options'
        ));
    }

    /**
     * Create a new cursor paginator instance.
     */
    protected function cursorPaginator(Collection $items, int $perPage, ?Cursor $cursor, array $options): CursorPaginator
    {
        return Container::getInstance()->makeWith(CursorPaginator::class, compact(
            'items',
            'perPage',
            'cursor',
            'options'
        ));
    }

    /**
     * Pass the query to a given callback and then return it.
     *
     * @param callable($this): mixed $callback
     * @return $this
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Pass the query to a given callback and return the result.
     *
     * @template TReturn
     *
     * @param (callable($this): TReturn) $callback
     * @return (TReturn is null|void ? $this : TReturn)
     */
    public function pipe(callable $callback)
    {
        return $callback($this) ?? $this;
    }
}
