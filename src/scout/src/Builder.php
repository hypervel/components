<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hyperf\Database\Connection;
use Hyperf\Paginator\LengthAwarePaginator;
use Hyperf\Paginator\Paginator;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hyperf\Contract\Arrayable;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;

use function Hyperf\Tappable\tap;

/**
 * Fluent search query builder for searchable models.
 *
 * @template TModel of Model&SearchableInterface
 */
class Builder
{
    use Conditionable;
    use Macroable;
    use Tappable;

    /**
     * The model instance.
     *
     * @var TModel
     */
    public Model $model;

    /**
     * The query expression.
     */
    public string $query;

    /**
     * Optional callback before search execution.
     */
    public ?Closure $callback;

    /**
     * Optional callback before model query execution.
     */
    public ?Closure $queryCallback = null;

    /**
     * Optional callback after raw search.
     */
    public ?Closure $afterRawSearchCallback = null;

    /**
     * The custom index specified for the search.
     */
    public ?string $index = null;

    /**
     * The "where" constraints added to the query.
     *
     * @var array<string, mixed>
     */
    public array $wheres = [];

    /**
     * The "where in" constraints added to the query.
     *
     * @var array<string, array<mixed>>
     */
    public array $whereIns = [];

    /**
     * The "where not in" constraints added to the query.
     *
     * @var array<string, array<mixed>>
     */
    public array $whereNotIns = [];

    /**
     * The "limit" that should be applied to the search.
     */
    public ?int $limit = null;

    /**
     * The "order" that should be applied to the search.
     *
     * @var array<array{column: string, direction: string}>
     */
    public array $orders = [];

    /**
     * Extra options that should be applied to the search.
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * Create a new search builder instance.
     *
     * @param TModel $model
     */
    public function __construct(
        Model $model,
        string $query,
        ?Closure $callback = null,
        bool $softDelete = false
    ) {
        $this->model = $model;
        $this->query = $query;
        $this->callback = $callback;

        if ($softDelete) {
            $this->wheres['__soft_deleted'] = 0;
        }
    }

    /**
     * Specify a custom index to perform this search on.
     *
     * @return $this
     */
    public function within(string $index): static
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Add a constraint to the search query.
     *
     * @return $this
     */
    public function where(string $field, mixed $value): static
    {
        $this->wheres[$field] = $value;

        return $this;
    }

    /**
     * Add a "where in" constraint to the search query.
     *
     * @param array<mixed>|Arrayable $values
     * @return $this
     */
    public function whereIn(string $field, array|Arrayable $values): static
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->whereIns[$field] = $values;

        return $this;
    }

    /**
     * Add a "where not in" constraint to the search query.
     *
     * @param array<mixed>|Arrayable $values
     * @return $this
     */
    public function whereNotIn(string $field, array|Arrayable $values): static
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->whereNotIns[$field] = $values;

        return $this;
    }

    /**
     * Include soft deleted records in the results.
     *
     * @return $this
     */
    public function withTrashed(): static
    {
        unset($this->wheres['__soft_deleted']);

        return $this;
    }

    /**
     * Include only soft deleted records in the results.
     *
     * @return $this
     */
    public function onlyTrashed(): static
    {
        return tap($this->withTrashed(), function () {
            $this->wheres['__soft_deleted'] = 1;
        });
    }

    /**
     * Set the "limit" for the search query.
     *
     * @return $this
     */
    public function take(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Add an "order" for the search query.
     *
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
        ];

        return $this;
    }

    /**
     * Add a descending "order by" clause to the search query.
     *
     * @return $this
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query (descending).
     *
     * @return $this
     */
    public function latest(?string $column = null): static
    {
        $column ??= $this->model->getCreatedAtColumn() ?? 'created_at';

        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query (ascending).
     *
     * @return $this
     */
    public function oldest(?string $column = null): static
    {
        $column ??= $this->model->getCreatedAtColumn() ?? 'created_at';

        return $this->orderBy($column, 'asc');
    }

    /**
     * Set extra options for the search query.
     *
     * @param array<string, mixed> $options
     * @return $this
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Set the callback that should have an opportunity to modify the database query.
     *
     * @return $this
     */
    public function query(callable $callback): static
    {
        $this->queryCallback = $callback(...);

        return $this;
    }

    /**
     * Get the raw results of the search.
     */
    public function raw(): mixed
    {
        return $this->engine()->search($this);
    }

    /**
     * Set the callback that should have an opportunity to inspect and modify
     * the raw result returned by the search engine.
     *
     * @return $this
     */
    public function withRawResults(callable $callback): static
    {
        $this->afterRawSearchCallback = $callback(...);

        return $this;
    }

    /**
     * Get the keys of search results.
     */
    public function keys(): Collection
    {
        return $this->engine()->keys($this);
    }

    /**
     * Get the first result from the search.
     *
     * @return null|TModel
     */
    public function first(): ?Model
    {
        return $this->get()->first();
    }

    /**
     * Get the results of the search.
     *
     * @return EloquentCollection<int, TModel>
     */
    public function get(): EloquentCollection
    {
        return $this->engine()->get($this);
    }

    /**
     * Get the results of the search as a lazy collection.
     *
     * @return LazyCollection<int, TModel>
     */
    public function cursor(): LazyCollection
    {
        return $this->engine()->cursor($this);
    }

    /**
     * Paginate the given query into a simple paginator.
     */
    public function simplePaginate(
        ?int $perPage = null,
        string $pageName = 'page',
        ?int $page = null
    ): Paginator {
        $engine = $this->engine();

        $page = $page ?? Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?? $this->model->getPerPage();

        $rawResults = $engine->paginate($this, $perPage, $page);
        /** @var array<TModel> $mappedModels */
        $mappedModels = $engine->map(
            $this,
            $this->applyAfterRawSearchCallback($rawResults),
            $this->model
        )->all();
        $results = $this->model->newCollection($mappedModels);

        return (new Paginator($results, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]))->hasMorePagesWhen(
            ($perPage * $page) < $engine->getTotalCount($rawResults)
        )->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a length-aware paginator.
     */
    public function paginate(
        ?int $perPage = null,
        string $pageName = 'page',
        ?int $page = null
    ): LengthAwarePaginator {
        $engine = $this->engine();

        $page = $page ?? Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?? $this->model->getPerPage();

        $rawResults = $engine->paginate($this, $perPage, $page);
        /** @var array<TModel> $mappedModels */
        $mappedModels = $engine->map(
            $this,
            $this->applyAfterRawSearchCallback($rawResults),
            $this->model
        )->all();
        $results = $this->model->newCollection($mappedModels);

        return (new LengthAwarePaginator(
            $results,
            $this->getTotalCount($rawResults),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        ))->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a length-aware paginator with raw data.
     */
    public function paginateRaw(
        ?int $perPage = null,
        string $pageName = 'page',
        ?int $page = null
    ): LengthAwarePaginator {
        $engine = $this->engine();

        $page = $page ?? Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?? $this->model->getPerPage();

        $results = $this->applyAfterRawSearchCallback(
            $engine->paginate($this, $perPage, $page)
        );

        return (new LengthAwarePaginator(
            $results,
            $this->getTotalCount($results),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        ))->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a simple paginator with raw data.
     */
    public function simplePaginateRaw(
        ?int $perPage = null,
        string $pageName = 'page',
        ?int $page = null
    ): Paginator {
        $engine = $this->engine();

        $page = $page ?? Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?? $this->model->getPerPage();

        $results = $this->applyAfterRawSearchCallback(
            $engine->paginate($this, $perPage, $page)
        );

        return (new Paginator($results, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]))->hasMorePagesWhen(
            ($perPage * $page) < $engine->getTotalCount($results)
        )->appends('query', $this->query);
    }

    /**
     * Get the total number of results from the Scout engine,
     * or fallback to query builder.
     */
    protected function getTotalCount(mixed $results): int
    {
        $engine = $this->engine();
        $totalCount = $engine->getTotalCount($results);

        if ($this->queryCallback === null) {
            return $totalCount;
        }

        $ids = $engine->mapIdsFrom($results, $this->model->getScoutKeyName())->all();

        if (count($ids) < $totalCount) {
            $ids = $engine->keys(
                tap(clone $this, function ($builder) use ($totalCount) {
                    $builder->take(
                        $this->limit === null ? $totalCount : min($this->limit, $totalCount)
                    );
                })
            )->all();
        }

        return $this->model->queryScoutModelsByIds($this, $ids)
            ->toBase()
            ->getCountForPagination();
    }

    /**
     * Invoke the "after raw search" callback.
     */
    public function applyAfterRawSearchCallback(mixed $results): mixed
    {
        if ($this->afterRawSearchCallback !== null) {
            $results = call_user_func($this->afterRawSearchCallback, $results) ?? $results;
        }

        return $results;
    }

    /**
     * Get the engine that should handle the query.
     */
    protected function engine(): Engine
    {
        return $this->model->searchableUsing();
    }

    /**
     * Get the connection type for the underlying model.
     */
    public function modelConnectionType(): string
    {
        /** @var Connection $connection */
        $connection = $this->model->getConnection();

        return $connection->getDriverName();
    }
}
