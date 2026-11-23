<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Attributes\SearchUsingPrefix;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Contracts\SupportsSemanticSearch;
use Hypervel\Scout\Engines\Concerns\PaginatesDatabaseVectorSearch;
use Hypervel\Scout\Engines\Concerns\PerformsDatabaseVectorSearch;
use Hypervel\Scout\SearchableSchema;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;

use function blank;

/**
 * Database search engine implementation.
 *
 * Searches model tables directly, with vector search available through PostgreSQL's pgvector extension.
 */
class DatabaseEngine extends Engine implements PaginatesEloquentModelsUsingDatabase, SupportsSemanticSearch
{
    use PaginatesDatabaseVectorSearch;
    use PerformsDatabaseVectorSearch;

    /**
     * The connections known to have the pg_trgm extension, keyed by connection name, database, and search path.
     *
     * Unlike upstream, this is held on the engine, which the manager keeps for the worker lifetime, instead of in a static property.
     *
     * @var array<string, true>
     */
    protected array $trigramExtensionInstalled = [];

    /**
     * Cached searchable attribute columns by model class and attribute class.
     *
     * @var array<class-string<Model>, array<class-string, array<string>>>
     */
    protected array $attributeColumns = [];

    /**
     * Cached full-text attribute options by model class.
     *
     * @var array<class-string<Model>, array<string, mixed>>
     */
    protected array $fullTextOptions = [];

    /**
     * Perform a search against the engine.
     *
     * @return array{results: EloquentCollection<int, Model&SearchableInterface>, total: int}
     */
    public function search(Builder $builder): array
    {
        if ($this->shouldPerformHybridSearch($builder)) {
            $models = $this->hybridSearchModels($builder, min($builder->limit ?? 1000, 1000));

            return [
                'results' => $models,
                'total' => $models->count(),
            ];
        }

        $models = $this->searchModels($builder);

        return [
            'results' => $models,
            'total' => $models->count(),
        ];
    }

    /**
     * Perform a paginated search against the engine.
     */
    public function paginate(Builder $builder, int $perPage, int $page): LengthAwarePaginatorContract
    {
        return $this->paginateUsingDatabase($builder, $perPage, 'page', $page);
    }

    /**
     * Paginate the given search on the engine using database pagination.
     */
    public function paginateUsingDatabase(
        Builder $builder,
        ?int $perPage,
        string $pageName,
        ?int $page
    ): LengthAwarePaginatorContract {
        if ($this->shouldPerformHybridSearch($builder)) {
            return $this->paginateHybridSearch($builder, $perPage, $pageName, $page);
        }

        if ($builder->semanticSearch) {
            return $this->paginateSemanticSearch($builder, $perPage, $pageName, $page);
        }

        return $this->searchWithTrigramFallback(
            $builder,
            fn (Builder $builder): LengthAwarePaginatorContract => $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
                ->paginate($perPage, ['*'], $pageName, $page),
            fn (LengthAwarePaginatorContract $paginator): bool => $paginator->total() === 0,
        );
    }

    /**
     * Paginate the given search on the engine using simple pagination.
     */
    public function simplePaginate(Builder $builder, int $perPage, int $page): PaginatorContract
    {
        return $this->simplePaginateUsingDatabase($builder, $perPage, 'page', $page);
    }

    /**
     * Paginate the given search on the engine using simple database pagination.
     */
    public function simplePaginateUsingDatabase(
        Builder $builder,
        ?int $perPage,
        string $pageName,
        ?int $page
    ): PaginatorContract {
        if ($this->shouldPerformHybridSearch($builder)) {
            return $this->simplePaginateHybridSearch($builder, $perPage, $pageName, $page);
        }

        if ($builder->semanticSearch) {
            return $this->simplePaginateSemanticSearch($builder, $perPage, $pageName, $page);
        }

        return $this->searchWithTrigramFallback(
            $builder,
            fn (Builder $builder): PaginatorContract => $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
                ->simplePaginate($perPage, ['*'], $pageName, $page),
            // Unlike upstream, an empty later page falls back only when the search matched nothing at all, so every page
            // belongs to the same result set; otherwise a page past the last full-text match would return trigram matches.
            // The probe reads the first page, since pagination replaces any offset and limit on the query.
            fn (PaginatorContract $paginator): bool => $paginator->isEmpty()
                && ($paginator->currentPage() === 1 || ! $this->buildSearchQuery($builder)->forPage(1, 1)->exists()),
        );
    }

    /**
     * Get the Eloquent models for the given builder.
     *
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    protected function searchModels(Builder $builder, ?int $page = null, ?int $perPage = null): EloquentCollection
    {
        if ($builder->semanticSearch) {
            /** @var EloquentCollection<int, Model&SearchableInterface> */
            return $this->buildSemanticSearchQuery($builder)
                ->when($page !== null && $perPage !== null, function (EloquentBuilder $query) use ($page, $perPage): void {
                    $query->forPage($page, $perPage);
                })
                ->get();
        }

        /** @var EloquentCollection<int, Model&SearchableInterface> */
        return $this->searchWithTrigramFallback(
            $builder,
            fn (Builder $builder): EloquentCollection => $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
                ->when($page !== null && $perPage !== null, function (EloquentBuilder $query) use ($page, $perPage): void {
                    $query->forPage($page, $perPage);
                })
                ->get(),
            fn (EloquentCollection $models): bool => $models->isEmpty(),
        );
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

        return $this->constrainForSoftDeletes(
            $builder,
            $this->addAdditionalConstraints($builder, $query->take($builder->limit))
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
        return $this->addTextSearchConstraints(
            $this->newSearchQuery($builder),
            $builder,
            $columns,
            $prefixColumns,
            $fullTextColumns
        );
    }

    /**
     * Create the model query used for a Scout search.
     */
    protected function newSearchQuery(Builder $builder): EloquentBuilder
    {
        return method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();
    }

    /**
     * Add text search constraints to the given query.
     *
     * @param array<string> $columns
     * @param array<string> $prefixColumns
     * @param array<string> $fullTextColumns
     */
    protected function addTextSearchConstraints(
        EloquentBuilder $query,
        Builder $builder,
        array $columns,
        array $prefixColumns = [],
        array $fullTextColumns = []
    ): EloquentBuilder {
        if (method_exists($builder->model, 'toSearchableEmbedding')) {
            $embeddingColumn = $this->embeddingColumn($builder->model);

            $columns = array_values(array_diff($columns, [$embeddingColumn]));
            $prefixColumns = array_values(array_diff($prefixColumns, [$embeddingColumn]));
            $fullTextColumns = array_values(array_diff($fullTextColumns, [$embeddingColumn]));
        }

        if (blank($builder->query)) {
            return $query;
        }

        $connectionType = $builder->modelConnectionType();

        return $query->where(function (EloquentBuilder $query) use ($connectionType, $builder, $columns, $prefixColumns, $fullTextColumns): void {
            $primaryKey = $builder->model->getKeyName();
            $integerPrimaryKey = in_array($builder->model->getKeyType(), ['int', 'integer'], true);
            $primaryKeyValue = $builder->query;
            $canSearchPrimaryKey = ctype_digit((string) $builder->query)
                && $integerPrimaryKey
                && in_array($primaryKey, $columns, true);

            if ($canSearchPrimaryKey && $connectionType === 'pgsql') {
                $primaryKeyValue = ltrim((string) $builder->query, '0') ?: '0';
                $canSearchPrimaryKey = filter_var($primaryKeyValue, FILTER_VALIDATE_INT) !== false;
            }

            if ($canSearchPrimaryKey) {
                $query->orWhere($builder->model->getQualifiedKeyName(), $primaryKeyValue);
            }

            $likeOperator = $connectionType === 'pgsql' ? 'ilike' : 'like';

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns, true)) {
                    continue;
                }

                if ($integerPrimaryKey && $column === $primaryKey) {
                    continue;
                }

                $pattern = in_array($column, $prefixColumns, true)
                    ? $builder->query . '%'
                    : '%' . $builder->query . '%';

                $query->orWhere(
                    $builder->model->qualifyColumn($column),
                    $likeOperator,
                    $pattern
                );
            }

            if ($this->searchesStoredVectors($builder)) {
                foreach ($fullTextColumns as $column) {
                    $query->orWhereRaw(
                        "{$this->wrapColumn($builder, $column)} @@ {$this->tsQueryExpression($builder)}",
                        $this->tsQueryBindings($builder)
                    );
                }
            } elseif (count($fullTextColumns) > 0 && $connectionType === 'pgsql') {
                // Unlike upstream, each vector of a multi-column search is coalesced, so a null column doesn't hide the
                // row; this matches the multi-column full-text index expression the schema grammar creates.
                $vectors = collect($fullTextColumns)
                    ->map(fn (string $column): string => count($fullTextColumns) > 1
                        ? "coalesce({$this->tsVectorExpression($builder, $column)}, ''::tsvector)"
                        : $this->tsVectorExpression($builder, $column))
                    ->implode(' || ');

                $query->orWhereRaw("({$vectors}) @@ {$this->tsQueryExpression($builder)}", $this->tsQueryBindings($builder));
            } elseif (count($fullTextColumns) > 0) {
                $query->orWhereFullText(
                    array_map(fn (string $column): string => $builder->model->qualifyColumn($column), $fullTextColumns),
                    $builder->query,
                    $this->getFullTextOptions($builder)
                );
            }

            foreach ($this->getTrigramColumns($builder) as $column) {
                $query->orWhereRaw("{$this->wrapColumn($builder, $column)} % ?", [$builder->query]);
            }
        });
    }

    /**
     * Run the given search, retrying with trigram matching when it finds nothing.
     *
     * @template TResult
     *
     * @param Closure(Builder): TResult $search
     * @param Closure(TResult): bool $isEmpty
     * @return TResult
     */
    protected function searchWithTrigramFallback(Builder $builder, Closure $search, Closure $isEmpty): mixed
    {
        if ($builder->modelConnectionType() === 'pgsql') {
            $this->ensureValidFullTextOptions($this->getFullTextOptions($builder));
        }

        $fallback = $this->shouldFallbackToTrigram($builder);

        $results = $search($builder);

        if (! $fallback || ! $isEmpty($results)) {
            return $results;
        }

        return $search((clone $builder)->options(array_merge($builder->options, ['trigram_mode' => 'blend'])));
    }

    /**
     * Determine if an empty search should be retried using trigram matching.
     */
    protected function shouldFallbackToTrigram(Builder $builder): bool
    {
        $options = $this->getFullTextOptions($builder);

        return ($options['trigram_mode'] ?? 'fallback') === 'fallback'
            && $this->usesTrigram($builder);
    }

    /**
     * Get the columns that should be matched using trigram similarity for the current search.
     *
     * @return array<int, string>
     */
    protected function getTrigramColumns(Builder $builder): array
    {
        $options = $this->getFullTextOptions($builder);

        if (($options['trigram_mode'] ?? 'fallback') !== 'blend') {
            return [];
        }

        return $this->usesTrigram($builder) ? Arr::wrap($options['trigram']) : [];
    }

    /**
     * Determine if the given search should use trigram matching.
     */
    protected function usesTrigram(Builder $builder): bool
    {
        if (blank($builder->query) || empty($this->getFullTextOptions($builder)['trigram']) || $builder->modelConnectionType() !== 'pgsql') {
            return false;
        }

        $this->ensureTrigramExtensionIsInstalled($builder);

        return true;
    }

    /**
     * Ensure the pg_trgm functions are reachable on the model's connection search path.
     *
     * @throws RuntimeException
     */
    protected function ensureTrigramExtensionIsInstalled(Builder $builder): void
    {
        $connection = $builder->model->getConnection();

        $key = implode('|', [
            $connection->getName(),
            $connection->getDatabaseName(),
            json_encode($connection->getConfig('search_path') ?? $connection->getConfig('schema'), JSON_THROW_ON_ERROR),
        ]);

        if (isset($this->trigramExtensionInstalled[$key])) {
            return;
        }

        if (! $connection->selectOne("select to_regprocedure('similarity(text,text)') is not null as installed")->installed) {
            throw new RuntimeException("Scout trigram search requires the pg_trgm extension on the [{$connection->getName()}] connection's search path.");
        }

        $this->trigramExtensionInstalled[$key] = true;
    }

    /**
     * Determine if the full-text columns are stored tsvector columns (Postgres only).
     */
    protected function searchesStoredVectors(Builder $builder): bool
    {
        return ! empty($this->getFullTextOptions($builder)['vector']) && $builder->modelConnectionType() === 'pgsql';
    }

    /**
     * Get the tsvector SQL expression for the given full-text column.
     */
    protected function tsVectorExpression(Builder $builder, string $column): string
    {
        if ($this->searchesStoredVectors($builder)) {
            return $this->wrapColumn($builder, $column);
        }

        $language = $this->getFullTextOptions($builder)['language'] ?? 'english';

        SearchableSchema::ensureValidLanguage($language);

        return "to_tsvector('{$language}', {$this->wrapColumn($builder, $column)})";
    }

    /**
     * Get the tsquery SQL expression for the search.
     */
    protected function tsQueryExpression(Builder $builder): string
    {
        $function = match ($this->getFullTextOptions($builder)['mode'] ?? null) {
            'phrase' => 'phraseto_tsquery',
            'websearch' => 'websearch_to_tsquery',
            'raw' => 'to_tsquery',
            default => 'plainto_tsquery',
        };

        return "{$function}(?::regconfig, ?)";
    }

    /**
     * Get the bindings for the tsquery SQL expression.
     *
     * @return array{string, string}
     */
    protected function tsQueryBindings(Builder $builder): array
    {
        return [$this->getFullTextOptions($builder)['language'] ?? 'english', $builder->query];
    }

    /**
     * Qualify and wrap the given column for use in raw SQL.
     */
    protected function wrapColumn(Builder $builder, string $column): string
    {
        return $builder->model->getConnection()->getQueryGrammar()->wrap($builder->model->qualifyColumn($column));
    }

    /**
     * Add the ordering clauses to the search query.
     */
    protected function orderSearchQuery(Builder $builder, EloquentBuilder $query): EloquentBuilder
    {
        return $query->when(count($builder->orders) > 0, function (EloquentBuilder $query) use ($builder): void {
            foreach ($builder->orders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }
        })->when(count($this->getFullTextColumns($builder)) === 0, function (EloquentBuilder $query) use ($builder): void {
            $query->orderBy($builder->model->getQualifiedKeyName(), 'desc');
        })->when($this->shouldOrderByRelevance($builder), function (EloquentBuilder $query) use ($builder): void {
            $this->orderByRelevance($builder, $query);

            $query->orderBy($builder->model->getQualifiedKeyName(), 'desc');
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
        $options = $this->getFullTextOptions($builder);

        $rank = match ($options['rank'] ?? null) {
            'ts_rank_cd' => 'ts_rank_cd',
            default => 'ts_rank',
        };

        $vectors = collect($this->getFullTextColumns($builder))
            ->map(fn (string $column): string => "coalesce({$this->tsVectorExpression($builder, $column)}, ''::tsvector)")
            ->implode(' || ');

        $score = "{$rank}({$vectors}, {$this->tsQueryExpression($builder)})";
        $bindings = $this->tsQueryBindings($builder);

        $trigramColumns = $this->getTrigramColumns($builder);

        if ($trigramColumns === []) {
            return $query->orderByRaw("{$score} desc", $bindings);
        }

        $similarity = collect($trigramColumns)->map(fn (string $column): string => "similarity({$this->wrapColumn($builder, $column)}, ?)")->implode(', ');

        $score = "{$score} + (coalesce(greatest({$similarity}), 0) * 0.25)";

        $bindings = array_merge($bindings, array_fill(0, count($trigramColumns), $builder->query));

        return $query->orderByRaw("{$score} desc", $bindings);
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
                foreach ($builder->wheres as $where) {
                    if ($where['field'] !== '__soft_deleted') {
                        $query->where($where['field'], $where['operator'], $where['value']);
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
        $softDeleteWhere = collect($builder->wheres)->firstWhere('field', '__soft_deleted');

        if ($softDeleteWhere !== null && $softDeleteWhere['value'] === 0) {
            /* @phpstan-ignore method.notFound (SoftDeletes adds this method via global scope) */
            return $query->withoutTrashed();
        }

        if ($softDeleteWhere !== null && $softDeleteWhere['value'] === 1) {
            /* @phpstan-ignore method.notFound (SoftDeletes adds this method via global scope) */
            return $query->onlyTrashed();
        }

        $usesSoftDeletes = in_array(
            SoftDeletes::class,
            class_uses_recursive(get_class($builder->model)),
            true
        );

        if ($usesSoftDeletes && config()->boolean('scout.soft_delete')) {
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
        $modelClass = $builder->model::class;

        if (array_key_exists($attributeClass, $this->attributeColumns[$modelClass] ?? [])) {
            return $this->attributeColumns[$modelClass][$attributeClass];
        }

        $columns = [];

        $reflection = new ReflectionMethod($builder->model, 'toSearchableArray');

        foreach ($reflection->getAttributes() as $attribute) {
            if ($attribute->getName() !== $attributeClass) {
                continue;
            }

            /** @var SearchUsingFullText|SearchUsingPrefix $attributeInstance */
            $attributeInstance = $attribute->newInstance();
            $columns = array_merge($columns, $attributeInstance->columns);
        }

        if (in_array($builder->model->getKeyType(), ['int', 'integer'], true)) {
            $columns = array_values(array_filter(
                $columns,
                fn (string $column): bool => $column !== $builder->model->getKeyName()
            ));
        }

        return $this->attributeColumns[$modelClass][$attributeClass] = $columns;
    }

    /**
     * Get the full-text search options for the query.
     *
     * @return array<string, mixed>
     */
    protected function getFullTextOptions(Builder $builder): array
    {
        $modelClass = $builder->model::class;

        if (! array_key_exists($modelClass, $this->fullTextOptions)) {
            $options = [];

            $reflection = new ReflectionMethod($builder->model, 'toSearchableArray');

            foreach ($reflection->getAttributes(SearchUsingFullText::class) as $attribute) {
                /** @var SearchUsingFullText $attributeInstance */
                $attributeInstance = $attribute->newInstance();
                $options = array_merge($options, $attributeInstance->options);
            }

            $this->fullTextOptions[$modelClass] = $options;
        }

        if ($builder->options === []) {
            return $this->fullTextOptions[$modelClass];
        }

        return array_merge($this->fullTextOptions[$modelClass], Arr::only($builder->options, ['mode', 'language', 'trigram', 'trigram_mode', 'rank', 'expanded']));
    }

    /**
     * Ensure the given PostgreSQL full-text options are supported.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException
     */
    protected function ensureValidFullTextOptions(array $options): void
    {
        if (isset($options['rank']) && ! in_array($options['rank'], ['ts_rank', 'ts_rank_cd'], true)) {
            throw new InvalidArgumentException('Invalid full-text search option: [rank] must be one of [ts_rank, ts_rank_cd].');
        }

        if (isset($options['trigram_mode']) && ! in_array($options['trigram_mode'], ['fallback', 'blend'], true)) {
            throw new InvalidArgumentException('Invalid full-text search option: [trigram_mode] must be one of [fallback, blend].');
        }
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
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        return $results['results'];
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
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
     * @param EloquentCollection<int, Model> $models
     */
    public function update(EloquentCollection $models): void
    {
        $this->updateSearchableEmbeddings($models);
    }

    /**
     * Limit vector import concurrency to the imported models' connection pool.
     */
    public function importConcurrency(Model $model, int $configured): int
    {
        if (! method_exists($model, 'toSearchableEmbedding') || ! $this->supportsVectorSearch($model)) {
            return $configured;
        }

        $name = $model->getConnection()->getName();
        $pool = $name === null ? null : Container::getInstance()->make(PoolManager::class)->existing($name);

        // The chunk reader retains one slot while admitting child imports.
        return $pool === null ? $configured : min($configured, $pool->getOptions()->maxConnections - 1);
    }

    /**
     * Observe embedding updates while bypassing no-op database indexing.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function runUpdate(EloquentCollection $models): void
    {
        $model = $models->first();

        if ($model !== null
            && method_exists($model, 'toSearchableEmbedding')
            && $this->supportsVectorSearch($model)) {
            parent::runUpdate($models);

            return;
        }

        $this->update($models);
    }

    /**
     * Remove the given models from the search index.
     *
     * The database engine doesn't need to remove from an external index.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function delete(EloquentCollection $models): void
    {
        // No-op: The database is the index.
    }

    /**
     * Delete the given models without observing an external index operation.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function runDelete(EloquentCollection $models): void
    {
        $this->delete($models);
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        // No-op: The database is the index.
    }

    /**
     * Flush the given model without observing an external index operation.
     */
    public function runFlush(Model $model): void
    {
        $this->flush($model);
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
}
