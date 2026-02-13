<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Hypervel\Container\Container;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Attributes\SearchUsingPrefix;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engine;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use ReflectionMethod;

use function blank;

/**
 * Database search engine implementation.
 *
 * Searches directly in the database using LIKE queries and optional full-text
 * search. This engine requires no external services and works with any database
 * that Hypervel supports.
 */
class DatabaseEngine extends Engine implements PaginatesEloquentModelsUsingDatabase
{
    /**
     * Perform a search against the engine.
     *
     * @return array{results: EloquentCollection<int, Model&SearchableInterface>, total: int}
     */
    public function search(Builder $builder): array
    {
        $models = $this->searchModels($builder);

        return [
            'results' => $models,
            'total' => $models->count(),
        ];
    }

    /**
     * Perform a paginated search against the engine.
     *
     * @return array{results: EloquentCollection<int, Model&SearchableInterface>, total: int}
     */
    public function paginate(Builder $builder, int $perPage, int $page): array
    {
        $models = $this->searchModels($builder, $page, $perPage);

        return [
            'results' => $models,
            'total' => $this->buildSearchQuery($builder)->count(),
        ];
    }

    /**
     * Paginate the given search on the engine using database pagination.
     */
    public function paginateUsingDatabase(
        Builder $builder,
        int $perPage,
        string $pageName,
        int $page
    ): LengthAwarePaginatorContract {
        return $this->buildSearchQuery($builder)
            ->when(count($builder->orders) > 0, function (EloquentBuilder $query) use ($builder): void {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            })
            ->when(count($this->getFullTextColumns($builder)) === 0, function (EloquentBuilder $query) use ($builder): void {
                $query->orderBy(
                    $builder->model->getTable() . '.' . $builder->model->getScoutKeyName(),
                    'desc'
                );
            })
            ->when($this->shouldOrderByRelevance($builder), function (EloquentBuilder $query) use ($builder): void {
                $this->orderByRelevance($builder, $query);
            })
            ->paginate($perPage, ['*'], $pageName, $page);
    }

    /**
     * Paginate the given search on the engine using simple database pagination.
     */
    public function simplePaginateUsingDatabase(
        Builder $builder,
        int $perPage,
        string $pageName,
        int $page
    ): PaginatorContract {
        return $this->buildSearchQuery($builder)
            ->when(count($builder->orders) > 0, function (EloquentBuilder $query) use ($builder): void {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            })
            ->when(count($this->getFullTextColumns($builder)) === 0, function (EloquentBuilder $query) use ($builder): void {
                $query->orderBy(
                    $builder->model->getTable() . '.' . $builder->model->getScoutKeyName(),
                    'desc'
                );
            })
            ->when($this->shouldOrderByRelevance($builder), function (EloquentBuilder $query) use ($builder): void {
                $this->orderByRelevance($builder, $query);
            })
            ->simplePaginate($perPage, ['*'], $pageName, $page);
    }

    /**
     * Get the Eloquent models for the given builder.
     *
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    protected function searchModels(Builder $builder, ?int $page = null, ?int $perPage = null): EloquentCollection
    {
        /** @var EloquentCollection<int, Model&SearchableInterface> */
        return $this->buildSearchQuery($builder)
            ->when($page !== null && $perPage !== null, function (EloquentBuilder $query) use ($page, $perPage): void {
                $query->forPage($page, $perPage);
            })
            ->when(count($builder->orders) > 0, function (EloquentBuilder $query) use ($builder): void {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            })
            ->when(count($this->getFullTextColumns($builder)) === 0, function (EloquentBuilder $query) use ($builder): void {
                $query->orderBy(
                    $builder->model->getTable() . '.' . $builder->model->getScoutKeyName(),
                    'desc'
                );
            })
            ->when($this->shouldOrderByRelevance($builder), function (EloquentBuilder $query) use ($builder): void {
                $this->orderByRelevance($builder, $query);
            })
            ->get();
    }

    /**
     * Initialize / build the search query for the given Scout builder.
     */
    protected function buildSearchQuery(Builder $builder): EloquentBuilder
    {
        $query = $this->initializeSearchQuery(
            $builder,
            array_keys($builder->model->toSearchableArray()),
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder)
        );

        $queryWithLimit = $builder->limit !== null ? $query->take($builder->limit) : $query;

        return $this->constrainForSoftDeletes(
            $builder,
            $this->addAdditionalConstraints($builder, $queryWithLimit)
        );
    }

    /**
     * Build the initial text search database query for all relevant columns.
     *
     * @param array<string> $columns
     * @param array<string> $prefixColumns
     * @param array<string> $fullTextColumns
     */
    protected function initializeSearchQuery(
        Builder $builder,
        array $columns,
        array $prefixColumns = [],
        array $fullTextColumns = []
    ): EloquentBuilder {
        $query = method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();

        if (blank($builder->query)) {
            return $query;
        }

        $connectionType = $builder->modelConnectionType();

        return $query->where(function (EloquentBuilder $query) use ($connectionType, $builder, $columns, $prefixColumns, $fullTextColumns): void {
            $canSearchPrimaryKey = ctype_digit((string) $builder->query)
                && in_array($builder->model->getKeyType(), ['int', 'integer'])
                && ($connectionType !== 'pgsql' || (int) $builder->query <= PHP_INT_MAX)
                && in_array($builder->model->getScoutKeyName(), $columns);

            if ($canSearchPrimaryKey) {
                $query->orWhere($builder->model->getQualifiedKeyName(), $builder->query);
            }

            $likeOperator = $connectionType === 'pgsql' ? 'ilike' : 'like';

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns)) {
                    continue;
                }

                if ($canSearchPrimaryKey && $column === $builder->model->getScoutKeyName()) {
                    continue;
                }

                $pattern = in_array($column, $prefixColumns)
                    ? $builder->query . '%'
                    : '%' . $builder->query . '%';

                $query->orWhere(
                    $builder->model->qualifyColumn($column),
                    $likeOperator,
                    $pattern
                );
            }

            if (count($fullTextColumns) > 0) {
                $qualifiedColumns = array_map(
                    fn (string $column): string => $builder->model->qualifyColumn($column),
                    $fullTextColumns
                );

                $query->orWhereFullText(
                    $qualifiedColumns,
                    $builder->query,
                    $this->getFullTextOptions($builder)
                );
            }
        });
    }

    /**
     * Determine if the query should be ordered by relevance.
     */
    protected function shouldOrderByRelevance(Builder $builder): bool
    {
        // MySQL orders by relevance by default, so we will only order by relevance on
        // Postgres with no developer-defined orders.
        return $builder->modelConnectionType() === 'pgsql'
            && count($this->getFullTextColumns($builder)) > 0
            && empty($builder->orders);
    }

    /**
     * Add an "order by" clause that orders by relevance (Postgres only).
     */
    protected function orderByRelevance(Builder $builder, EloquentBuilder $query): EloquentBuilder
    {
        $fullTextColumns = $this->getFullTextColumns($builder);
        $options = $this->getFullTextOptions($builder);
        $language = $options['language'] ?? 'english';

        $vectors = collect($fullTextColumns)
            ->map(fn (string $column): string => sprintf(
                "to_tsvector('%s', %s)",
                $language,
                $builder->model->qualifyColumn($column)
            ))
            ->implode(' || ');

        $tsQueryFunction = match ($options['mode'] ?? 'plainto_tsquery') {
            'phrase' => 'phraseto_tsquery',
            'websearch' => 'websearch_to_tsquery',
            default => 'plainto_tsquery',
        };

        $query->orderByRaw(
            sprintf('ts_rank(%s, %s(?)) desc', $vectors, $tsQueryFunction),
            [$builder->query]
        );

        return $query;
    }

    /**
     * Add additional, developer defined constraints to the search query.
     */
    protected function addAdditionalConstraints(Builder $builder, EloquentBuilder $query): EloquentBuilder
    {
        return $query
            ->when($builder->callback !== null, function (EloquentBuilder $query) use ($builder): void {
                call_user_func($builder->callback, $query, $builder, $builder->query);
            })
            ->when($builder->callback === null && count($builder->wheres) > 0, function (EloquentBuilder $query) use ($builder): void {
                foreach ($builder->wheres as $key => $value) {
                    if ($key !== '__soft_deleted') {
                        $query->where($key, '=', $value);
                    }
                }
            })
            ->when($builder->callback === null && count($builder->whereIns) > 0, function (EloquentBuilder $query) use ($builder): void {
                foreach ($builder->whereIns as $key => $values) {
                    $query->whereIn($key, $values);
                }
            })
            ->when($builder->callback === null && count($builder->whereNotIns) > 0, function (EloquentBuilder $query) use ($builder): void {
                foreach ($builder->whereNotIns as $key => $values) {
                    $query->whereNotIn($key, $values);
                }
            })
            ->when($builder->queryCallback !== null, function (EloquentBuilder $query) use ($builder): void {
                call_user_func($builder->queryCallback, $query);
            });
    }

    /**
     * Ensure that soft delete constraints are properly applied to the query.
     */
    protected function constrainForSoftDeletes(Builder $builder, EloquentBuilder $query): EloquentBuilder
    {
        $softDeletedValue = Arr::get($builder->wheres, '__soft_deleted');

        if ($softDeletedValue === 0) {
            /* @phpstan-ignore method.notFound (SoftDeletes adds this method via global scope) */
            return $query->withoutTrashed();
        }

        if ($softDeletedValue === 1) {
            /* @phpstan-ignore method.notFound (SoftDeletes adds this method via global scope) */
            return $query->onlyTrashed();
        }

        $usesSoftDeletes = in_array(
            SoftDeletes::class,
            class_uses_recursive(get_class($builder->model))
        );

        if ($usesSoftDeletes && $this->getConfig('soft_delete', false)) {
            /* @phpstan-ignore method.notFound (SoftDeletes adds this method via global scope) */
            return $query->withTrashed();
        }

        return $query;
    }

    /**
     * Get the full-text columns for the query.
     *
     * @return array<string>
     */
    protected function getFullTextColumns(Builder $builder): array
    {
        return $this->getAttributeColumns($builder, SearchUsingFullText::class);
    }

    /**
     * Get the prefix search columns for the query.
     *
     * @return array<string>
     */
    protected function getPrefixColumns(Builder $builder): array
    {
        return $this->getAttributeColumns($builder, SearchUsingPrefix::class);
    }

    /**
     * Get the columns marked with a given attribute.
     *
     * @param class-string $attributeClass
     * @return array<string>
     */
    protected function getAttributeColumns(Builder $builder, string $attributeClass): array
    {
        $columns = [];

        $reflection = new ReflectionMethod($builder->model, 'toSearchableArray');

        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() !== $attributeClass) {
                continue;
            }

            $columns = array_merge($columns, Arr::wrap($attribute->getArguments()[0]));
        }

        return $columns;
    }

    /**
     * Get the full-text search options for the query.
     *
     * @return array<string, mixed>
     */
    protected function getFullTextOptions(Builder $builder): array
    {
        $options = [];

        $reflection = new ReflectionMethod($builder->model, 'toSearchableArray');

        foreach ($reflection->getAttributes(SearchUsingFullText::class) as $attribute) {
            $arguments = $attribute->getArguments()[1] ?? [];
            $options = array_merge($options, Arr::wrap($arguments));
        }

        return $options;
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        /** @var EloquentCollection<int, Model> $collection */
        $collection = $results['results'];

        return $collection->isNotEmpty()
            ? collect($collection->modelKeys())
            : collect();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param Model&SearchableInterface $model
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        return $results['results'];
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param Model&SearchableInterface $model
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        /** @var EloquentCollection<int, Model&SearchableInterface> $collection */
        $collection = $results['results'];

        return new LazyCollection($collection->all());
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount(mixed $results): int
    {
        return (int) $results['total'];
    }

    /**
     * Update the given models in the search index.
     *
     * The database engine doesn't need to update an external index.
     *
     * @param EloquentCollection<int, Model&SearchableInterface> $models
     */
    public function update(EloquentCollection $models): void
    {
        // No-op: The database is the index.
    }

    /**
     * Remove the given models from the search index.
     *
     * The database engine doesn't need to remove from an external index.
     *
     * @param EloquentCollection<int, Model&SearchableInterface> $models
     */
    public function delete(EloquentCollection $models): void
    {
        // No-op: The database is the index.
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param Model&SearchableInterface $model
     */
    public function flush(Model $model): void
    {
        // No-op: The database is the index.
    }

    /**
     * Create a search index.
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        // No-op: The database table is the index.
        return null;
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex(string $name): mixed
    {
        // No-op: The database table is the index.
        return null;
    }

    /**
     * Get a Scout configuration value.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return Container::getInstance()
            ->make('config')
            ->get("scout.{$key}", $default);
    }
}
