<?php

declare(strict_types=1);

namespace Hypervel\Database\Concerns;

use Generator;
use Hypervel\Container\Container;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\RecordNotFoundException;
use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;
use RuntimeException;
use SortDirection;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @mixin QueryBuilder<TKey, TValue>
 */
trait BuildsQueries
{
    use Conditionable;

    /**
     * Chunk the results of the query.
     *
     * @param callable(Collection<TKey, TValue>, int): mixed $callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $clone = clone $this;

        $clone->enforceOrderBy();

        $skip = $clone->getOffset();
        $remaining = $clone->getLimit();

        $page = 1;

        do {
            $offset = (($page - 1) * $count) + (int) $skip;

            $limit = is_null($remaining) ? $count : min($count, $remaining);

            if ($limit === 0) {
                break;
            }

            $results = $clone->offset($offset)->limit($limit)->get();

            $countResults = $results->count();

            if ($countResults === 0) {
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
        } while ($countResults === $count);

        return true;
    }

    /**
     * Run a map over each item while chunking.
     *
     * @template TReturn
     *
     * @param callable(TValue): TReturn $callback
     * @return Collection<int, TReturn>
     */
    public function chunkMap(callable $callback, int $count = 1000): Collection
    {
        $collection = new Collection;

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
            $position = 0;

            foreach ($results as $value) {
                if ($callback($value, $position++) === false) {
                    return false;
                }
            }
        });
    }

    /**
     * Chunk the results of a query by comparing IDs.
     *
     * @param callable(Collection<TKey, TValue>, int): mixed $callback
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     *
     * @param callable(Collection<TKey, TValue>, int): mixed $callback
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias, descending: SortDirection::Descending);
    }

    /**
     * Chunk the results of a query by comparing IDs in a given order.
     *
     * @param callable(Collection<TKey, TValue>, int): mixed $callback
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, SortDirection|bool $descending = false): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $column ??= $this->defaultKeyName();
        $alias ??= $column;
        $this->ensureLimitedIterationOrdersById($column, $descending === true || $descending === SortDirection::Descending);
        $lastId = null;
        $skip = $this->getOffset();
        $remaining = $this->getLimit();
        $limited = $remaining !== null || $skip > 0;

        $page = 1;

        do {
            $clone = clone $this;

            if ($skip && $page > 1) {
                $clone->offset(0);
            }

            $limit = is_null($remaining) ? $count : min($count, $remaining);

            if ($limit === 0) {
                break;
            }

            // We'll execute the query for the given page and get the results. If there are
            // no results we can just break and return from here. When there are results
            // we will call the callback with the current chunk of these results here.
            $results = match ($descending) {
                SortDirection::Ascending, false => $clone->forPageAfterId($limit, $lastId, $column)->prioritizeKeysetOrders($limited)->get(),
                SortDirection::Descending, true => $clone->forPageBeforeId($limit, $lastId, $column)->prioritizeKeysetOrders($limited)->get(),
            };

            $countResults = $results->count();

            if ($countResults === 0) {
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

            $lastId = $this->getNextPageIdFromChunk($results->last(), $column, $alias);

            if ($lastId === null) {
                throw new RuntimeException("The chunkById operation was aborted because the [{$alias}] column is not present in the query result.");
            }

            unset($results);

            ++$page;
        } while ($countResults === $count);

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
            $position = 0;

            foreach ($results as $value) {
                if ($callback($value, (($page - 1) * $count) + $position++) === false) {
                    return false;
                }
            }
        }, $column, $alias);
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @return LazyCollection<int, TValue>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $skip = $this->getOffset();
        $remaining = $this->getLimit();

        return new LazyCollection(function () use ($chunkSize, $skip, $remaining): Generator {
            $clone = clone $this;

            $clone->enforceOrderBy();

            $page = 1;

            while (true) {
                $offset = (($page - 1) * $chunkSize) + (int) $skip;

                $limit = is_null($remaining) ? $chunkSize : min($chunkSize, $remaining);

                if ($limit === 0) {
                    return;
                }

                $results = $clone->offset($offset)->limit($limit)->get();

                foreach ($results as $result) {
                    yield $result;
                }

                $countResults = $results->count();

                if (! is_null($remaining)) {
                    $remaining = max($remaining - $countResults, 0);
                }

                if ($countResults < $chunkSize) {
                    return;
                }

                unset($results);

                ++$page;
            }
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     *
     * @return LazyCollection<int, TValue>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     *
     * @return LazyCollection<int, TValue>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias, SortDirection::Descending);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in a given order.
     *
     * @return LazyCollection<int, TValue>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException if the ID column is missing while iterating the results
     */
    protected function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, SortDirection|bool $descending = false): LazyCollection
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $column ??= $this->defaultKeyName();

        $alias ??= $column;

        $this->ensureLimitedIterationOrdersById($column, $descending === true || $descending === SortDirection::Descending);
        $skip = $this->getOffset();
        $remaining = $this->getLimit();
        $limited = $remaining !== null || $skip > 0;

        return new LazyCollection(function () use ($chunkSize, $column, $alias, $descending, $skip, $remaining, $limited): Generator {
            $lastId = null;

            $page = 1;

            while (true) {
                $clone = clone $this;

                if ($skip && $page > 1) {
                    $clone->offset(0);
                }

                $limit = is_null($remaining) ? $chunkSize : min($chunkSize, $remaining);

                if ($limit === 0) {
                    return;
                }

                $results = match ($descending) {
                    SortDirection::Ascending, false => $clone->forPageAfterId($limit, $lastId, $column)->prioritizeKeysetOrders($limited)->get(),
                    SortDirection::Descending, true => $clone->forPageBeforeId($limit, $lastId, $column)->prioritizeKeysetOrders($limited)->get(),
                };

                foreach ($results as $result) {
                    yield $result;
                }

                $countResults = $results->count();

                if (! is_null($remaining)) {
                    $remaining = max($remaining - $countResults, 0);
                }

                if ($countResults < $chunkSize) {
                    return;
                }

                $lastId = $this->getNextPageIdFromChunk($results->last(), $column, $alias);

                if ($lastId === null) {
                    throw new RuntimeException("The lazyById operation was aborted because the [{$alias}] column is not present in the query result.");
                }

                unset($results);

                ++$page;
            }
        });
    }

    /**
     * Get the ID of the last result of a chunk to continue paging from.
     */
    protected function getLastIdFromChunk(mixed $result, string $alias): mixed
    {
        if (! $result instanceof Model) {
            return data_get($result, $alias);
        }

        // Callbacks and accessors can provide cursor aliases that are not selected columns.
        return array_key_exists($alias, $original = $result->getRawOriginal())
            ? $original[$alias]
            : data_get($result, $alias);
    }

    /**
     * Prepare the last chunk ID for comparison with the paging column.
     */
    protected function getNextPageIdFromChunk(mixed $result, string $column, string $alias): mixed
    {
        $lastId = $this->getLastIdFromChunk($result, $alias);

        if (! $result instanceof Model) {
            return $lastId;
        }

        // Either the selected alias or the model's paging column can declare binary binding intent.
        $attribute = $this->getPagingAttribute($result, $column);
        $lastId = $result->prepareBinaryAttributesForDatabase([$alias => $lastId])[$alias];

        return $result->prepareBinaryAttributesForDatabase([$attribute => $lastId])[$attribute];
    }

    /**
     * Get the model attribute behind a qualified paging column.
     */
    protected function getPagingAttribute(Model $model, string $column): string
    {
        if ($model->hasCast($column)) {
            return $column;
        }

        $prefixes = [$model->getTable() . '.'];

        $query = $this instanceof Builder ? $this->getQuery() : $this;

        if (($alias = $query->getFromAlias()) !== null) {
            $prefixes[] = $alias . '.';
        }

        return Str::chopStart($column, $prefixes);
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
     * @throws RecordNotFoundException
     */
    public function firstOrFail(array|string $columns = ['*'], ?string $message = null)
    {
        // Inspect collection presence so a matching scalar null row is not mistaken for no row.
        $results = $this->limit(1)->get($columns);

        if ($results->isNotEmpty()) {
            return $results->first();
        }

        throw new RecordNotFoundException($message ?: 'No record found for the given query.');
    }

    /**
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return TValue
     *
     * @throws RecordsNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*'])
    {
        $result = $this->limit(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw new RecordsNotFoundException;
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        // @phpstan-ignore return.type (Eloquent hydrates to TModel, not stdClass)
        return $result->first();
    }

    /**
     * Paginate the given query using a cursor paginator.
     */
    protected function paginateUsingCursor(
        int $perPage,
        array|string $columns = ['*'],
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ): CursorPaginator {
        if (! $cursor instanceof Cursor) {
            $cursor = is_string($cursor)
                ? Cursor::fromEncoded($cursor)
                : CursorPaginator::resolveCurrentCursor($cursorName, $cursor);
        }

        // The caller must provide the complete unique order. Adding scope keys here
        // would require extra selected values and can replace an index range with a compound filter.
        $orders = $this->ensureOrderForCursorPagination(! is_null($cursor) && $cursor->pointsToPreviousItems());
        $this->limit($perPage + 1);
        $builder = $this;

        if (! is_null($cursor)) {
            $query = $this instanceof Builder ? $this->getQuery() : $this;
            $builder = $this->getBuilderForCursorPage();
            $pageQuery = $builder instanceof Builder ? $builder->getQuery() : $builder;
            $originalColumns = $pageQuery->columns;
            $comparisons = [];
            $bindings = [];

            // Scopes may add computed aliases. Resolve against the page's scoped projection,
            // installing argument-only columns afterward so scopes still see their normal input.
            $pageQuery->columns ??= Arr::wrap($columns);
            try {
                foreach ($orders as $order) {
                    $column = $order['column'];
                    $original = $this->getOriginalColumnNameForCursorPagination($builder, $column);
                    $order['original'] = $this->isCursorExpression($pageQuery, $column, $original)
                        ? new Expression($original) : $original;
                    $comparisons[] = $order;
                    $bindings[$column] = $builder->getCursorParameterBinding($cursor, $column);
                }
            } finally {
                $pageQuery->columns = $originalColumns;
            }

            // Members are resolved by their output positions during SELECT preparation;
            // keep their select/join bindings and never rewrite a shared union builder.
            $query->addCursorPaginationConditions($comparisons, $bindings);

            if ($pageQuery !== $query) {
                // Keep the caller's page conditions as well as the scoped execution copy's.
                $pageQuery->addCursorPaginationConditions($comparisons, $bindings);
            }
        }

        return $this->cursorPaginator($builder->get($columns), $perPage, $cursor, [
            'path' => Paginator::resolveCurrentPath(),
            'cursorName' => $cursorName,
            'parameters' => $orders->pluck('column')->toArray(),
        ]);
    }

    /**
     * Get the builder that owns cursor binding and page execution.
     */
    protected function getBuilderForCursorPage(): static
    {
        return $this;
    }

    /**
     * Get a cursor parameter for database binding.
     */
    protected function getCursorParameterBinding(Cursor $cursor, string $parameter): mixed
    {
        return $cursor->parameter($parameter);
    }

    /**
     * Get the original column name of the given column, without any aliasing.
     *
     * @param  QueryBuilder|Builder<*>  $builder
     */
    protected function getOriginalColumnNameForCursorPagination(QueryBuilder|Builder $builder, string $parameter): string
    {
        $query = $builder instanceof Builder ? $builder->getQuery() : $builder;

        // Ordered expressions require a separate select entry with an explicit AS alias.
        // Implicit aliases and multiple fields in one raw entry remain unsupported: parsing
        // arbitrary SQL is brittle, while automatic wrapping can change row-lock behavior.
        foreach ($query->getColumns() as $column) {
            if (($position = strripos($column, ' as ')) !== false) {
                $original = substr($column, 0, $position);

                $alias = substr($column, $position + 4);

                if ($parameter === $alias || $query->getGrammar()->wrap($parameter) === $alias) {
                    return $original;
                }
            }
        }

        return $parameter;
    }

    /**
     * Determine whether the resolved cursor column is a declared SQL expression.
     */
    protected function isCursorExpression(QueryBuilder $query, string $parameter, string $original): bool
    {
        $grammar = $query->getGrammar();

        // Arithmetic need not contain parentheses, and literal identifiers may contain them.
        // Match the alias as well as its source so another selection cannot change its type.
        foreach ($query->columns ?? [] as $column) {
            $value = (string) $grammar->getValue($column);
            if (($position = strripos($value, ' as ')) !== false) {
                $alias = substr($value, $position + 4);

                if (substr($value, 0, $position) === $original
                    && ($parameter === $alias || $grammar->wrap($parameter) === $alias)) {
                    return $column instanceof ExpressionContract;
                }
            }
        }

        // Preserve custom alias-lookup overrides that return an unselected expression.
        return $original !== $parameter && Str::contains($original, ['(', ')']);
    }

    /**
     * Create a new length-aware paginator instance.
     */
    protected function paginator(Collection $items, int $total, int $perPage, int $currentPage, array $options): LengthAwarePaginator
    {
        return Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items' => $items, 'total' => $total, 'perPage' => $perPage, 'currentPage' => $currentPage, 'options' => $options,
        ]);
    }

    /**
     * Create a new simple paginator instance.
     */
    protected function simplePaginator(Collection $items, int $perPage, int $currentPage, array $options): Paginator
    {
        return Container::getInstance()->makeWith(Paginator::class, [
            'items' => $items, 'perPage' => $perPage, 'currentPage' => $currentPage, 'options' => $options,
        ]);
    }

    /**
     * Create a new cursor paginator instance.
     */
    protected function cursorPaginator(Collection $items, int $perPage, ?Cursor $cursor, array $options): CursorPaginator
    {
        return Container::getInstance()->makeWith(CursorPaginator::class, [
            'items' => $items, 'perPage' => $perPage, 'cursor' => $cursor, 'options' => $options,
        ]);
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
