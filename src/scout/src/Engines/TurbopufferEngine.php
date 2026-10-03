<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use BackedEnum;
use Closure;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Http\Client\RequestException;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Contracts\SupportsSemanticSearch;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Scout\Services\Turbopuffer\TurbopufferClient;
use Hypervel\Scout\Services\Turbopuffer\TurbopufferNamespace;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use InvalidArgumentException;

/**
 * Turbopuffer search engine implementation.
 *
 * Provides full-text, semantic, and hybrid search using Turbopuffer as the backend.
 */
class TurbopufferEngine extends Engine implements DeletesByFilter, SupportsSemanticSearch
{
    /**
     * Create a new Turbopuffer engine instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected TurbopufferClient $turbopuffer,
        protected array $config = [],
        protected bool $softDelete = false
    ) {
    }

    /**
     * Update the given models in the search index.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $model = $models->first();

        if ($this->usesSoftDelete($model) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $records = $models->map(function (Model $model): ?array {
            /** @var Model&SearchableInterface $model */
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                return null;
            }

            return [
                'model' => $model,
                'row' => array_merge(
                    $searchableData,
                    $model->scoutMetadata(),
                    ['id' => $model->getScoutKey()],
                ),
            ];
        })->filter()->values()->all();

        if (empty($records)) {
            return;
        }

        $settings = $this->modelSettings($model);

        $embeddingSettings = isset($settings['embedding'])
            ? $this->embeddingSettings($model)
            : null;

        if ($embeddingSettings !== null && ! $this->usesNativeEmbeddings($embeddingSettings)) {
            $records = $this->addEmbeddingsToRecords($records, $embeddingSettings);
        }

        $rows = array_map(function (array $record) use ($embeddingSettings): array {
            if ($embeddingSettings !== null && $this->usesNativeEmbeddings($embeddingSettings)) {
                unset($record['row'][$embeddingSettings['generated_attribute']]);
            }

            return Scout::prepareSearchableDocument($record['row'], $record['model'], $this);
        }, $records);

        $indexSettings = [];

        foreach (['schema', 'distance_metric'] as $option) {
            if (isset($settings[$option])) {
                $indexSettings[$option] = $settings[$option];
            }
        }

        if ($embeddingSettings !== null && $this->usesNativeEmbeddings($embeddingSettings)) {
            $embed = &$indexSettings['schema'][$embeddingSettings['attribute']]['embed'];

            if (is_array($embed) && isset($embed['dimensions'])) {
                $embed['dims'] = (int) $embed['dimensions'];

                unset($embed['dimensions']);
            }

            unset($embed);
        }

        if ($embeddingSettings !== null && ! isset($indexSettings['distance_metric'])) {
            $indexSettings['distance_metric'] = 'cosine_distance';
        }

        $namespace = $model->indexableAs();

        $this->turbopuffer->namespace($namespace)->write([
            'upsert_rows' => $rows,
            ...Scout::prepareIndexSettings($indexSettings, $model, $this, $namespace),
        ]);
    }

    /**
     * Add embeddings to the given searchable records.
     *
     * @param array<int, array{model: Model, row: array<string, mixed>}> $records
     * @param array<string, mixed> $settings
     * @return array<int, array{model: Model, row: array<string, mixed>}>
     */
    protected function addEmbeddingsToRecords(array $records, array $settings): array
    {
        foreach (array_chunk($records, 100, preserve_keys: true) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $record) {
                if (! method_exists($record['model'], 'toSearchableEmbedding')) {
                    throw new ScoutException('Searchable models using generated embeddings must define a [toSearchableEmbedding] method.');
                }

                $input = $record['model']->toSearchableEmbedding();

                if (is_array($input)) {
                    $vectors[$index] = $input;

                    continue;
                }

                if (! is_string($input) || trim($input) === '') {
                    throw new ScoutException('The [toSearchableEmbedding] method must return a non-empty string or an embedding array.');
                }

                $inputs[$index] = $input;
            }

            if (! empty($inputs)) {
                $generatedVectors = $this->generateEmbeddings(array_values($inputs), $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach (array_keys($batch) as $index) {
                $records[$index]['row'][$settings['attribute']] = $vectors[$index];
            }
        }

        return $records;
    }

    /**
     * Remove the given models from the search index.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function delete(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $model = $models->first();

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($model->getScoutKeyName())
            : $models->map(fn (Model $model): mixed => $model->getScoutKey());

        $namespace = $this->turbopuffer->namespace($model->indexableAs());

        $this->ignoringMissingNamespace(
            fn (): array => $namespace->write(['deletes' => $keys->values()->all()])
        );
    }

    /**
     * Delete every document matching the prepared Builder filters.
     */
    public function deleteByFilter(Builder $builder): void
    {
        Scout::prepareBuilder($builder, $this);

        $filters = $this->combineFilters($builder->options['filters'] ?? null, $this->filters($builder));

        if ($filters === null) {
            throw new InvalidArgumentException('Turbopuffer filter deletion requires a non-empty filter.');
        }

        $index = $builder->index ?? $builder->model->indexableAs();
        $namespace = $this->turbopuffer->namespace($index);

        $this->runOperation(
            'delete_by_filter',
            $builder,
            function () use ($namespace, $filters): void {
                $this->ignoringMissingNamespace(function () use ($namespace, $filters): void {
                    // Each request deletes up to Turbopuffer's per-request limit and reports whether matches remain.
                    do {
                        $result = $namespace->write([
                            'delete_by_filter' => $filters,
                            'delete_by_filter_allow_partial' => true,
                        ]);
                    } while ($result['rows_remaining'] ?? false);
                });
            },
            index: $index,
        );
    }

    /**
     * Perform a search against the engine.
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch(
            $builder,
            $builder->limit ?? $builder->model->getPerPage()
        );
    }

    /**
     * Perform a paginated search against the engine.
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $maximum = min($builder->limit ?? 10000, 10000);
        $window = $page * $perPage;
        $offset = $window - $perPage;

        // The last page may extend past 10,000 records, so only reject pages that start beyond them.
        if ($offset >= 10000) {
            throw new ScoutException('Turbopuffer search results may not be paginated beyond 10,000 records.');
        }

        $results = $this->performSearch($builder, min($window, $maximum));

        $results['rows'] = array_slice(
            $results['rows'] ?? [],
            $offset,
            $perPage
        );

        $count = $this->ignoringMissingNamespace(
            fn (): array => $this->namespace($builder)->query(array_filter([
                'aggregate_by' => ['count' => ['Count']],
                'filters' => $this->countFilters($builder),
                'consistency' => $builder->options['consistency'] ?? null,
            ], fn (mixed $value): bool => $value !== null)),
            [],
        );

        $results['total'] = min((int) ($count['aggregations']['count'] ?? 0), $maximum);

        return $results;
    }

    /**
     * Get the filters that count a paginated search's matches.
     *
     * Full-text rankings exclude documents without a query token, so the count
     * matches any token in the weighted searchable attributes as BM25 does.
     *
     * @return null|array<int, mixed>
     */
    protected function countFilters(Builder $builder): ?array
    {
        $filters = $this->combineFilters($builder->options['filters'] ?? null, $this->filters($builder));

        if ($builder->semanticSearch
            || $builder->hybridSearch !== null
            || isset($builder->options['rank_by'])
            || $builder->query === ''
            || $builder->query === '*') {
            return $filters;
        }

        $tokenFilters = [];

        foreach ($this->searchableAttributeWeights($builder) as $attribute => $weight) {
            if ($weight > 0) {
                $tokenFilters[] = [$attribute, 'ContainsAnyToken', $builder->query];
            }
        }

        $tokenFilter = count($tokenFilters) === 1 ? $tokenFilters[0] : ['Or', $tokenFilters];

        return $this->combineFilters($filters, $tokenFilter);
    }

    /**
     * Perform a search against Turbopuffer.
     *
     * @return array<string, mixed>
     */
    protected function performSearch(Builder $builder, int $limit): array
    {
        $namespace = $this->namespace($builder);

        $parameters = $this->buildSearchParameters($builder, $limit);

        $results = $this->ignoringMissingNamespace(
            fn (): mixed => $builder->callback !== null
                ? call_user_func($builder->callback, $namespace, $builder->query, $parameters)
                : $namespace->query($parameters),
            ['rows' => []],
        );

        if ($builder->hybridSearch !== null) {
            $results['rows'] = array_slice($results['results'][0]['rows'] ?? [], 0, $limit);
        }

        $results['total'] = count($results['rows'] ?? []);

        return $results;
    }

    /**
     * Run a namespace request, treating a missing namespace as empty.
     *
     * Turbopuffer creates namespaces on their first write and reports a 404 for
     * namespaces that were never written or have been flushed.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @return null|TResult
     */
    protected function ignoringMissingNamespace(Closure $callback, mixed $missing = null): mixed
    {
        try {
            return $callback();
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            return $missing;
        }
    }

    /**
     * Build Turbopuffer search parameters for the query.
     *
     * @return array<string, mixed>
     */
    public function buildSearchParameters(Builder $builder, int $limit): array
    {
        if (isset($builder->options['queries'])) {
            throw new ScoutException('Turbopuffer multi-query searches are not supported by this Scout engine.');
        }

        if ($builder->hybridSearch !== null) {
            return $this->buildHybridSearchParameters($builder, $limit);
        }

        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $scoutFilters = $this->filters($builder);

        unset($parameters['filters']);

        if ($builder->semanticSearch && isset($parameters['rank_by'])) {
            throw new ScoutException('Turbopuffer semantic searches cannot be combined with a custom ranking expression.');
        }

        if (! isset($parameters['rank_by'])) {
            $parameters['rank_by'] = $this->rankBy($builder);
        } elseif (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with a custom ranking expression.');
        }

        if (($filters = $this->combineFilters($nativeFilters, $scoutFilters)) !== null) {
            $parameters['filters'] = $filters;
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = min(max(1, $limit), 10000);

        return $parameters;
    }

    /**
     * Build a hybrid full-text and semantic query.
     *
     * @return array<string, mixed>
     */
    protected function buildHybridSearchParameters(Builder $builder, int $limit): array
    {
        if (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with hybrid search.');
        }

        foreach (['rank_by', 'rerank_by'] as $option) {
            if (isset($builder->options[$option])) {
                throw new ScoutException("Turbopuffer hybrid searches cannot be combined with a custom [{$option}] option.");
            }
        }

        /** @var array{text_weight: float|int, semantic_weight: float|int} $weights */
        $weights = $builder->hybridSearch;
        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $rootParameters = array_intersect_key($parameters, array_flip(['consistency', 'vector_encoding']));

        unset($parameters['consistency'], $parameters['filters'], $parameters['vector_encoding']);

        if (($filters = $this->combineFilters($nativeFilters, $this->filters($builder))) !== null) {
            $parameters['filters'] = $filters;
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = min(max(1, $limit), 10000);

        return array_merge($rootParameters, [
            'queries' => [
                array_merge($parameters, ['rank_by' => $this->fullTextRankBy($builder)]),
                array_merge($parameters, ['rank_by' => $this->semanticRankBy($builder)]),
            ],
            'rerank_by' => ['RRF', [
                'weights' => [
                    $weights['text_weight'],
                    $weights['semantic_weight'],
                ],
            ]],
        ]);
    }

    /**
     * Build the ranking expression for the query.
     *
     * @return array<int, mixed>
     */
    protected function rankBy(Builder $builder): array
    {
        if ($builder->semanticSearch) {
            if (! empty($builder->orders)) {
                throw new ScoutException('Turbopuffer order clauses cannot be combined with semantic search.');
            }

            return $this->semanticRankBy($builder);
        }

        if ($builder->query === '' || $builder->query === '*') {
            if (count($builder->orders) > 1) {
                throw new ScoutException('Turbopuffer supports one order clause per search.');
            }

            $order = $builder->orders[0] ?? ['column' => 'id', 'direction' => 'asc'];

            return [$this->field($builder, $order['column']), $order['direction']];
        }

        if (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with full-text search.');
        }

        return $this->fullTextRankBy($builder);
    }

    /**
     * Build the full-text ranking expression for a query.
     *
     * @return array<int, mixed>
     */
    protected function fullTextRankBy(Builder $builder): array
    {
        $expressions = [];

        foreach ($this->searchableAttributeWeights($builder) as $attribute => $weight) {
            $expression = [$attribute, 'BM25', $builder->query];

            $expressions[] = (float) $weight === 1.0
                ? $expression
                : ['Product', $weight, $expression];
        }

        return count($expressions) === 1
            ? $expressions[0]
            : ['Sum', $expressions];
    }

    /**
     * Get the validated BM25 weights of the model's searchable attributes.
     *
     * @return array<string, float|int|numeric-string>
     */
    protected function searchableAttributeWeights(Builder $builder): array
    {
        $attributes = $this->modelSettings($builder->model)['searchable-attributes'] ?? [];

        if (empty($attributes)) {
            throw new ScoutException('No Turbopuffer searchable attributes have been configured for [' . $builder->model::class . '].');
        }

        $weights = [];

        foreach ($attributes as $key => $value) {
            [$attribute, $weight] = is_int($key) ? [$value, 1] : [$key, $value];

            if (! is_string($attribute) || ! is_numeric($weight) || $weight < 0) {
                throw new ScoutException('Turbopuffer searchable attributes must contain attribute names with non-negative numeric weights.');
            }

            $weights[$attribute] = $weight;
        }

        return $weights;
    }

    /**
     * Build the semantic ranking expression for a query.
     *
     * @return array<int, mixed>
     */
    protected function semanticRankBy(Builder $builder): array
    {
        $settings = $this->embeddingSettings($builder->model);

        return [
            $settings['attribute'],
            'ANN',
            $this->usesNativeEmbeddings($settings)
                ? ['Embed', $builder->query]
                : $this->generateEmbeddings([$builder->query], $settings)[0],
        ];
    }

    /**
     * Build filters for the query.
     *
     * @return null|array<int, mixed>
     */
    protected function filters(Builder $builder): ?array
    {
        $filters = [];

        $operators = [
            '=' => 'Eq',
            '!=' => 'NotEq',
            '<' => 'Lt',
            '<=' => 'Lte',
            '>' => 'Gt',
            '>=' => 'Gte',
        ];

        foreach ($builder->wheres as $where) {
            if (! isset($operators[$where['operator']])) {
                throw new ScoutException("The [{$where['operator']}] operator is not supported by the Turbopuffer engine.");
            }

            $filters[] = [
                $this->field($builder, $where['field']),
                $operators[$where['operator']],
                $this->filterValue($where['value']),
            ];
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[] = [$this->field($builder, $field), 'In', array_map([$this, 'filterValue'], $values)];
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters[] = [$this->field($builder, $field), 'NotIn', array_map([$this, 'filterValue'], $values)];
        }

        return match (count($filters)) {
            0 => null,
            1 => $filters[0],
            default => ['And', $filters],
        };
    }

    /**
     * Combine native and Scout filters.
     *
     * @param null|array<int, mixed> $nativeFilters
     * @param null|array<int, mixed> $scoutFilters
     * @return null|array<int, mixed>
     */
    protected function combineFilters(?array $nativeFilters, ?array $scoutFilters): ?array
    {
        if ($nativeFilters === null || $nativeFilters === []) {
            return $scoutFilters;
        }

        if ($scoutFilters === null) {
            return $nativeFilters;
        }

        return ['And', [$nativeFilters, $scoutFilters]];
    }

    /**
     * Normalize a filter value.
     */
    protected function filterValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * Ensure the Scout key is returned for model hydration.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function ensureIdIsReturned(array $parameters): array
    {
        if (isset($parameters['include_attributes']) && is_array($parameters['include_attributes'])) {
            $parameters['include_attributes'] = array_values(array_unique([
                ...$parameters['include_attributes'],
                'id',
            ]));
        }

        if (isset($parameters['exclude_attributes']) && is_array($parameters['exclude_attributes'])) {
            $parameters['exclude_attributes'] = array_values(array_diff($parameters['exclude_attributes'], ['id']));
        }

        return $parameters;
    }

    /**
     * Resolve a Scout field name to a Turbopuffer field name.
     */
    protected function field(Builder $builder, string $field): string
    {
        return $field === $builder->model->getScoutKeyName() ? 'id' : $field;
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        return collect($results['rows'] ?? [])->pluck('id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        /** @var Model&SearchableInterface $model */
        if (empty($results['rows'])) {
            return $model->newCollection();
        }

        $rows = collect($results['rows']);
        $objectIds = $rows->pluck('id')->values()->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        $mapped = $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn (Model $model): bool => in_array($model->getScoutKey(), $objectIds, false))
            ->map(function (Model $model) use ($rows, $objectIdPositions): Model {
                $row = $rows[$objectIdPositions[$model->getScoutKey()]];

                if (array_key_exists('$dist', $row)) {
                    $model->withScoutMetadata('_turbopuffer_dist', $row['$dist']);
                }

                return $model;
            })
            ->sortBy(fn (Model $model): int => $objectIdPositions[$model->getScoutKey()])
            ->values();

        return $model->newCollection($mapped->all());
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        /** @var Model&SearchableInterface $model */
        if (empty($results['rows'])) {
            return LazyCollection::empty();
        }

        $rows = collect($results['rows']);
        $objectIds = $rows->pluck('id')->values()->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn (Model $model): bool => in_array($model->getScoutKey(), $objectIds, false))
            ->map(function (Model $model) use ($rows, $objectIdPositions): Model {
                $row = $rows[$objectIdPositions[$model->getScoutKey()]];

                if (array_key_exists('$dist', $row)) {
                    $model->withScoutMetadata('_turbopuffer_dist', $row['$dist']);
                }

                return $model;
            })
            ->sortBy(fn (Model $model): int => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount(mixed $results): int
    {
        return (int) ($results['total'] ?? count($results['rows'] ?? []));
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        /** @var Model&SearchableInterface $model */
        $this->ignoringMissingNamespace(fn (): array => $this->deleteIndex($model->indexableAs()));
    }

    /**
     * Create a search index.
     *
     * @throws NotSupportedException
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        throw new NotSupportedException('Turbopuffer namespaces are created automatically upon adding documents.');
    }

    /**
     * Delete a search index.
     *
     * @return array<string, mixed>
     */
    public function deleteIndex(string $name): array
    {
        return $this->turbopuffer->namespace($name)->delete();
    }

    /**
     * Get the configured settings for a model.
     *
     * @return array<string, mixed>
     */
    protected function modelSettings(Model $model): array
    {
        return $this->config['model-settings'][$model::class] ?? [];
    }

    /**
     * Get the validated embedding settings for a model.
     *
     * @return array<string, mixed>
     */
    protected function embeddingSettings(Model $model): array
    {
        $modelSettings = $this->modelSettings($model);

        $settings = $modelSettings['embedding'] ?? null;

        if (! is_array($settings)) {
            throw new ScoutException('No Turbopuffer embedding settings have been configured for [' . $model::class . '].');
        }

        $settings = $this->validateEmbeddingSettings($settings);

        if ($this->usesNativeEmbeddings($settings)) {
            $schema = $modelSettings['schema'][$settings['attribute']] ?? null;

            if (! is_array($schema) || ($schema['type'] ?? null) !== 'string') {
                throw new ScoutException("Turbopuffer native embeddings require a string schema configuration for the [{$settings['attribute']}] attribute.");
            }

            $settings['generated_attribute'] = $this->validateNativeEmbeddingSchema($settings['attribute'], $schema['embed'] ?? null);
        }

        return $settings;
    }

    /**
     * Validate embedding configuration shared by indexing and querying.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function validateEmbeddingSettings(array $settings): array
    {
        if (! isset($settings['attribute']) || ! is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ScoutException('Turbopuffer embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'hypervel-ai';

        if (! in_array($driver, ['hypervel-ai', 'turbopuffer'], true)) {
            throw new ScoutException("The [{$driver}] Turbopuffer embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNativeEmbeddings($settings)) {
            return $settings;
        }

        if (! isset($settings['dimensions'])
            || filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false
            || $settings['dimensions'] < 1) {
            throw new ScoutException('Turbopuffer embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Turbopuffer should generate embeddings natively.
     *
     * @param array<string, mixed> $settings
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'turbopuffer';
    }

    /**
     * Validate a native embedding schema and return its vector attribute.
     */
    protected function validateNativeEmbeddingSchema(string $attribute, mixed $embed): string
    {
        if (is_string($embed) && trim($embed) !== '') {
            return 'embed_' . $attribute;
        }

        if (! is_array($embed) || ! isset($embed['model']) || ! is_string($embed['model']) || trim($embed['model']) === '') {
            throw new ScoutException("Turbopuffer native embeddings require a valid [embed] schema configuration for the [{$attribute}] attribute.");
        }

        if (isset($embed['dimensions']) && (filter_var($embed['dimensions'], FILTER_VALIDATE_INT) === false || $embed['dimensions'] < 1)) {
            throw new ScoutException('Turbopuffer native embedding [dimensions] must be a positive integer.');
        }

        if (isset($embed['attribute']) && (! is_string($embed['attribute']) || trim($embed['attribute']) === '')) {
            throw new ScoutException('Turbopuffer native embedding [attribute] must be a non-empty string.');
        }

        return $embed['attribute'] ?? 'embed_' . $attribute;
    }

    /**
     * Generate embeddings for the given inputs.
     *
     * @param array<int, string> $inputs
     * @param array<string, mixed> $settings
     * @return array<int, array<int, float|int>>
     */
    protected function generateEmbeddings(array $inputs, array $settings): array
    {
        throw new ScoutException('AI-generated embeddings are not available in Hypervel. Use native or precomputed embeddings instead.');
    }

    /**
     * Get the Turbopuffer namespace for a search.
     */
    protected function namespace(Builder $builder): TurbopufferNamespace
    {
        return $this->turbopuffer->namespace(
            $builder->index ?? $builder->model->searchableAs()
        );
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
