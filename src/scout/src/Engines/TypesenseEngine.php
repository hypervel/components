<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use BackedEnum;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Contracts\SupportsSemanticSearch;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use InvalidArgumentException;
use stdClass;
use Typesense\Client as Typesense;
use Typesense\Collection as TypesenseCollection;
use Typesense\Documents;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ObjectUnprocessable;
use Typesense\Exceptions\RequestMalformed;
use Typesense\Exceptions\RequestUnauthorized;
use Typesense\Exceptions\ServiceUnavailable;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Typesense search engine implementation.
 *
 * Provides full-text, semantic, and hybrid search using Typesense as the backend.
 */
class TypesenseEngine extends Engine implements DeletesByFilter, SupportsSemanticSearch
{
    /**
     * The maximum number of results that can be fetched per page.
     */
    private int $maxPerPage = 250;

    /**
     * Create a new TypesenseEngine instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected Typesense $typesense,
        protected int $maxTotalResults,
        protected array $config = []
    ) {
    }

    /**
     * Update the given models in the search index.
     *
     * @param EloquentCollection<int, Model> $models
     * @throws TypesenseClientError
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $firstModel = $models->first();

        if ($this->usesSoftDelete($firstModel) && config()->boolean('scout.soft_delete')) {
            $models->each->pushSoftDeleteMetadata();
        }

        $records = $models->map(function (Model $model): ?array {
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                return null;
            }

            return [
                'model' => $model,
                'object' => array_merge(
                    $searchableData,
                    $model->scoutMetadata(),
                ),
            ];
        })
            ->filter()
            ->values()
            ->all();

        if (empty($records)) {
            return;
        }

        $embedding = isset($this->modelSettings($firstModel)['embedding'])
            ? $this->embeddingSettings($firstModel)
            : null;

        if ($embedding !== null && ! $this->usesNativeEmbeddings($embedding)) {
            $records = $this->addEmbeddingsToRecords($records, $embedding);
        }

        $objects = array_map(
            fn (array $record): array => Scout::prepareSearchableDocument($record['object'], $record['model'], $this),
            $records,
        );

        $collectionName = $firstModel->indexableAs();
        $collection = $this->collection($collectionName);

        try {
            $this->importDocuments($collection, $objects);
        } catch (ObjectNotFound) {
            $this->createCollectionFromModel($firstModel, $collectionName);
            $this->importDocuments($collection, $objects);
        }
    }

    /**
     * Add embeddings to the given searchable records.
     *
     * @param array<int, array{model: Model, object: array<string, mixed>}> $records
     * @param array<string, mixed> $settings
     * @return array<int, array{model: Model, object: array<string, mixed>}>
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
                $records[$index]['object'][$settings['attribute']] = $vectors[$index];
            }
        }

        return $records;
    }

    /**
     * Import the given documents into the index.
     *
     * @param array<array<string, mixed>> $documents
     * @return Collection<int, stdClass>
     * @throws TypesenseClientError
     */
    protected function importDocuments(
        TypesenseCollection $collectionIndex,
        array $documents,
        ?string $action = null
    ): Collection {
        $action = $action ?? $this->getConfig('typesense.import_action', 'upsert');

        /** @var array<array{success: bool, error?: string, code?: int, document?: string}> $importedDocuments */
        $importedDocuments = $collectionIndex->getDocuments()->import($documents, ['action' => $action]);

        $results = [];

        foreach ($importedDocuments as $importedDocument) {
            if (! $importedDocument['success']) {
                throw new TypesenseClientError("Error importing document: {$importedDocument['error']}");
            }

            $results[] = $this->createImportSortingDataObject($importedDocument);
        }

        return collect($results);
    }

    /**
     * Create an import sorting data object for a given document.
     *
     * @param array{success: bool, error?: string, code?: int, document?: string} $document
     */
    protected function createImportSortingDataObject(array $document): stdClass
    {
        $data = new stdClass;

        $data->code = $document['code'] ?? 0;
        $data->success = $document['success'];
        $data->error = $document['error'] ?? null;
        $data->document = json_decode($document['document'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Remove the given models from the search index.
     *
     * @param EloquentCollection<int, Model> $models
     * @throws TypesenseClientError
     */
    public function delete(EloquentCollection $models): void
    {
        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $models->each(function (Model $model) use ($models): void {
            $modelId = $models instanceof RemoveableScoutCollection
                ? $model->getAttribute($model->getScoutKeyName())
                : $model->getScoutKey();

            $this->deleteDocument(
                $this->collection($model->indexableAs()),
                $modelId,
            );
        });
    }

    /**
     * Delete a document from the index.
     *
     * Returns an empty array if the document doesn't exist (idempotent delete).
     * Other errors (network, auth, etc.) are allowed to bubble up.
     *
     * @return array<string, mixed>
     * @throws TypesenseClientError
     */
    protected function deleteDocument(TypesenseCollection $collectionIndex, mixed $modelId): array
    {
        $document = $collectionIndex->getDocuments()[(string) $modelId];

        try {
            return $document->delete();
        } catch (ObjectNotFound) {
            return [];
        }
    }

    /**
     * Perform a search against the engine.
     *
     * @throws TypesenseClientError
     */
    public function search(Builder $builder): mixed
    {
        // If the limit exceeds Typesense's capabilities, perform a paginated search
        if ($builder->limit !== null && $builder->limit >= $this->maxPerPage) {
            return $this->performPaginatedSearch($builder);
        }

        // Cap per_page by both maxPerPage (Typesense limit) and maxTotalResults (config limit)
        $perPage = min($builder->limit ?? $this->maxPerPage, $this->maxPerPage, $this->maxTotalResults);

        return $this->performSearch(
            $builder,
            $this->buildSearchParameters($builder, 1, $perPage)
        );
    }

    /**
     * Perform a paginated search against the engine.
     *
     * @throws TypesenseClientError
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $page = max(1, $page);

        if ($perPage < 1 || $perPage > $this->maxPerPage) {
            throw new InvalidArgumentException(
                "Typesense pagination requires perPage to be between 1 and {$this->maxPerPage}."
            );
        }

        return $this->performSearch(
            $builder,
            $this->buildSearchParameters($builder, $page, $perPage)
        );
    }

    /**
     * Perform the given search on the engine.
     *
     * @param array<string, mixed> $options
     * @throws TypesenseClientError
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $collectionName = $builder->index ?? $builder->model->searchableAs();
        $documents = $this->collection($collectionName)->getDocuments();

        if ($builder->callback !== null) {
            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        try {
            return $this->executeSearch($builder, $documents, $options);
        } catch (ObjectNotFound) {
            $this->createCollectionFromModel($builder->model, $collectionName);

            return $this->executeSearch($builder, $documents, $options);
        }
    }

    /**
     * Execute the given search using the appropriate Typesense endpoint.
     *
     * @param array<string, mixed> $options
     * @throws TypesenseClientError
     */
    protected function executeSearch(Builder $builder, Documents $documents, array $options): mixed
    {
        if (! isset($options['vector_query'])) {
            return $documents->search($options);
        }

        // Serialized embeddings may exceed Typesense's query string length, so send them in a multi-search body.
        $results = $this->typesense->getMultiSearch()->perform([
            'searches' => [
                array_merge($options, [
                    'collection' => $builder->index ?? $builder->model->searchableAs(),
                ]),
            ],
        ]);

        $result = $results['results'][0] ?? [];

        if (isset($result['error'])) {
            throw $this->marshalMultiSearchException($result);
        }

        return $result;
    }

    /**
     * Convert a multi-search error result into a Typesense exception.
     *
     * @param array{code?: int, error: string} $result
     */
    protected function marshalMultiSearchException(array $result): TypesenseClientError
    {
        $exception = match ((int) ($result['code'] ?? 500)) {
            400 => new RequestMalformed,
            401 => new RequestUnauthorized,
            404 => new ObjectNotFound,
            409 => new ObjectAlreadyExists,
            422 => new ObjectUnprocessable,
            503 => new ServiceUnavailable,
            default => new TypesenseClientError,
        };

        return $exception->setMessage($result['error']);
    }

    /**
     * Perform a paginated search on the engine.
     *
     * @return array{hits: array<mixed>, found: int, out_of: int, page: int, request_params: array<string, mixed>}
     * @throws TypesenseClientError
     */
    protected function performPaginatedSearch(Builder $builder): array
    {
        /** @var int $builderLimit */
        $builderLimit = $builder->limit;
        $target = min($builderLimit, $this->maxTotalResults);
        $perPage = min($target, $this->maxPerPage);
        $page = 1;
        $results = new Collection;
        $totalFound = 0;
        $totalOutOf = 0;
        $firstPageParameters = $this->buildSearchParameters($builder, $page, $perPage);
        $requestParameters = $firstPageParameters;

        while ($results->count() < $target) {
            /** @var array{hits?: array<mixed>, found?: int, out_of?: int} $searchResults */
            $searchResults = $this->performSearch($builder, $requestParameters);
            $hits = $searchResults['hits'] ?? [];

            $results = $results->concat($hits);

            if ($page === 1) {
                $totalFound = $searchResults['found'] ?? 0;
                $totalOutOf = $searchResults['out_of'] ?? 0;
            }

            if ($results->count() >= $target
                || $results->count() >= $totalFound
                || count($hits) < $perPage) {
                break;
            }

            ++$page;
            $requestParameters = $this->buildSearchParameters($builder, $page, $perPage);
        }

        return [
            'hits' => $results->take($target)->all(),
            'found' => $totalFound,
            'out_of' => $totalOutOf,
            'page' => 1,
            'request_params' => $firstPageParameters,
        ];
    }

    /**
     * Build the search parameters for a given Scout query builder.
     *
     * @return array<string, mixed>
     */
    public function buildSearchParameters(Builder $builder, int $page, ?int $perPage): array
    {
        $modelClass = get_class($builder->model);
        $modelSettings = $this->getConfig("typesense.model-settings.{$modelClass}.search-parameters", []);

        $parameters = [
            'q' => $builder->query,
            'query_by' => $modelSettings['query_by'] ?? '',
            'filter_by' => '',
            'per_page' => $perPage,
            'page' => $page,
            'highlight_start_tag' => '<mark>',
            'highlight_end_tag' => '</mark>',
            'snippet_threshold' => 30,
            'exhaustive_search' => false,
            'use_cache' => false,
            'cache_ttl' => 60,
            'prioritize_exact_match' => true,
            'enable_overrides' => true,
            'highlight_affix_num_tokens' => 4,
            'prefix' => $modelSettings['prefix'] ?? true,
        ];

        if (method_exists($builder->model, 'typesenseSearchParameters')) {
            $parameters = array_merge($parameters, $builder->model->typesenseSearchParameters());
        }

        if (! empty($builder->options)) {
            $parameters = array_merge($parameters, $builder->options);
        }

        $parameters['filter_by'] = $this->combineFilters(
            $parameters['filter_by'] ?? '',
            $this->filters($builder),
        );

        if (! empty($builder->orders)) {
            if (! empty($parameters['sort_by'])) {
                $parameters['sort_by'] .= ',';
            } else {
                $parameters['sort_by'] = '';
            }

            $parameters['sort_by'] .= $this->parseOrderBy($builder->orders);
        }

        $parameters['page'] = $page;
        $parameters['per_page'] = $perPage;

        return $this->applySemanticSearchParameters($builder, $parameters);
    }

    /**
     * Apply semantic and hybrid search parameters to the search parameters.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function applySemanticSearchParameters(Builder $builder, array $parameters): array
    {
        if (! $builder->semanticSearch && $builder->hybridSearch === null) {
            return $parameters;
        }

        if (array_key_exists('vector_query', $builder->options)) {
            throw new ScoutException('Typesense semantic and hybrid searches cannot be combined with a custom [vector_query] option.');
        }

        $settings = $this->embeddingSettings($builder->model);

        unset($parameters['vector']);

        if ($builder->semanticSearch) {
            $parameters = $this->usesNativeEmbeddings($settings)
                ? $this->applyNativeSemanticQueryBy($parameters, $settings['attribute'])
                : array_merge($parameters, ['q' => '*']);
        } else {
            $parameters = $this->applyHybridQueryBy($parameters, $settings);
        }

        if (($vectorQuery = $this->buildVectorQueryParameter($builder, $settings)) !== null) {
            $parameters['vector_query'] = $vectorQuery;
        }

        $parameters['exclude_fields'] = $this->appendField($parameters['exclude_fields'] ?? '', $settings['attribute']);

        return $parameters;
    }

    /**
     * Target the embedding field for a semantic search using native embeddings.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    protected function applyNativeSemanticQueryBy(array $parameters, string $attribute): array
    {
        $parameters['query_by'] = $attribute;

        // Remote embedders reject prefix searches on embedding fields.
        $parameters['prefix'] = false;

        unset($parameters['query_by_weights']);

        foreach (['num_typos', 'infix'] as $parameter) {
            if (isset($parameters[$parameter]) && str_contains((string) $parameters[$parameter], ',')) {
                unset($parameters[$parameter]);
            }
        }

        return $parameters;
    }

    /**
     * Prepare the "query_by" fields and their per-field parameters for a hybrid search.
     *
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function applyHybridQueryBy(array $parameters, array $settings): array
    {
        $fields = array_filter(array_map('trim', explode(',', (string) ($parameters['query_by'] ?? ''))));

        if (empty(array_diff($fields, [$settings['attribute']]))) {
            throw new ScoutException('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');
        }

        if (! $this->usesNativeEmbeddings($settings)) {
            return $parameters;
        }

        if (! in_array($settings['attribute'], $fields, true)) {
            $fields[] = $settings['attribute'];
            $parameters['query_by'] = implode(',', $fields);

            if (isset($parameters['query_by_weights']) && $parameters['query_by_weights'] !== '') {
                $parameters['query_by_weights'] .= ',0';
            }

            foreach (['num_typos' => '0', 'infix' => 'off'] as $parameter => $placeholder) {
                if (isset($parameters[$parameter]) && str_contains((string) $parameters[$parameter], ',')) {
                    $parameters[$parameter] .= ',' . $placeholder;
                }
            }
        }

        // Remote embedders reject prefix searches on embedding fields, so prefix search must be disabled for that field.
        if ($parameters['prefix'] === true || $parameters['prefix'] === 'true') {
            $prefixes = array_fill(0, count($fields), 'true');
        } elseif (is_string($parameters['prefix']) && str_contains($parameters['prefix'], ',')) {
            $prefixes = explode(',', $parameters['prefix']);
        } else {
            return $parameters;
        }

        $prefixes[array_search($settings['attribute'], array_values($fields), true)] = 'false';
        $parameters['prefix'] = implode(',', $prefixes);

        return $parameters;
    }

    /**
     * Build the "vector_query" parameter for the search.
     *
     * @param array<string, mixed> $settings
     */
    protected function buildVectorQueryParameter(Builder $builder, array $settings): ?string
    {
        if ($this->usesNativeEmbeddings($settings)) {
            $vector = [];
        } else {
            $vector = $builder->options['vector']
                ?? $this->generateEmbeddings([$builder->query], $settings)[0];

            if (! is_array($vector) || empty($vector)) {
                throw new ScoutException('The Typesense query [vector] must be a non-empty embedding array.');
            }
        }

        $options = [];

        if ($builder->hybridSearch !== null) {
            $options[] = 'alpha: ' . ($builder->hybridSearch['semantic_weight'] / array_sum($builder->hybridSearch));
        }

        if ($builder->minimumSimilarity !== null) {
            $options[] = 'distance_threshold: ' . $this->distanceThreshold($builder->minimumSimilarity);
        }

        if (empty($vector) && empty($options)) {
            return null;
        }

        return sprintf(
            '%s:([%s]%s)',
            $settings['attribute'],
            implode(', ', $vector),
            empty($options) ? '' : ', ' . implode(', ', $options)
        );
    }

    /**
     * Get the maximum vector distance for the given minimum similarity.
     */
    protected function distanceThreshold(float|int $similarity): float|int
    {
        if ($similarity < 0 || $similarity > 1) {
            throw new ScoutException('The minimum similarity must be between 0 and 1.');
        }

        return 1 - $similarity;
    }

    /**
     * Append a field to a comma-separated field list if not already present.
     */
    protected function appendField(string $fields, string $field): string
    {
        $fields = array_filter(array_map('trim', explode(',', $fields)));

        if (! in_array($field, $fields, true)) {
            $fields[] = $field;
        }

        return implode(',', $fields);
    }

    /**
     * Combine application and Builder filters without changing their precedence.
     */
    protected function combineFilters(string $applicationFilters, string $builderFilters): string
    {
        if (trim($applicationFilters) === '') {
            return $builderFilters;
        }

        if ($builderFilters === '') {
            return $applicationFilters;
        }

        return "({$applicationFilters}) && ({$builderFilters})";
    }

    /**
     * Prepare the filters for a given search query.
     */
    protected function filters(Builder $builder): string
    {
        $whereFilter = collect($builder->wheres)
            ->map(fn (array $where): string => $this->parseWhereFilter(
                $this->parseFilterValue($where['value']),
                $where['field'],
                $where['operator'],
            ))
            ->values()
            ->implode(' && ');

        $whereInFilter = collect($builder->whereIns)
            ->map(fn (array $value, string $key): string => $this->parseWhereInFilter($this->parseFilterValue($value), $key))
            ->values()
            ->implode(' && ');

        $whereNotInFilter = collect($builder->whereNotIns)
            ->map(fn (array $value, string $key): string => $this->parseWhereNotInFilter($this->parseFilterValue($value), $key))
            ->values()
            ->implode(' && ');

        return collect([$whereFilter, $whereInFilter, $whereNotInFilter])
            ->filter()
            ->implode(' && ');
    }

    /**
     * Parse the given filter value.
     *
     * @param array<mixed>|BackedEnum|bool|float|int|string $value
     * @return array<mixed>|float|int|string
     */
    protected function parseFilterValue(array|string|bool|int|float|BackedEnum $value): array|string|int|float
    {
        if (is_array($value)) {
            return array_map([$this, 'parseFilterValue'], $value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * Create a "where" filter string.
     *
     * @param array<mixed>|float|int|string $value
     */
    protected function parseWhereFilter(
        array|string|int|float $value,
        string $key,
        string $operator = '='
    ): string {
        $operator = match ($operator) {
            '=' => ':=',
            '!=' => ':!=',
            '<' => ':<',
            '>' => ':>',
            '<=' => ':<=',
            '>=' => ':>=',
            default => throw new InvalidArgumentException("Unsupported Typesense filter operator [{$operator}]."),
        };

        return is_array($value)
            ? sprintf('%s%s%s', $key, $operator, implode('', $value))
            : sprintf('%s%s%s', $key, $operator, $value);
    }

    /**
     * Create a "where in" filter string.
     *
     * @param array<mixed> $value
     */
    protected function parseWhereInFilter(array $value, string $key): string
    {
        return sprintf('%s:=[%s]', $key, implode(', ', $value));
    }

    /**
     * Create a "where not in" filter string.
     *
     * @param array<mixed> $value
     */
    protected function parseWhereNotInFilter(array $value, string $key): string
    {
        return sprintf('%s:!=[%s]', $key, implode(', ', $value));
    }

    /**
     * Parse the order by fields for the query.
     *
     * @param array<array{column: string, direction: string}> $orders
     */
    protected function parseOrderBy(array $orders): string
    {
        $orderBy = [];

        foreach ($orders as $order) {
            $orderBy[] = $order['column'] . ':' . $order['direction'];
        }

        return implode(',', $orderBy);
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        return collect($results['hits'] ?? [])
            ->pluck('document.id')
            ->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        /** @var Model&SearchableInterface $model */
        if ($this->getTotalCount($results) === 0) {
            return $model->newCollection();
        }

        $hits = isset($results['grouped_hits']) && ! empty($results['grouped_hits'])
            ? $results['grouped_hits']
            : $results['hits'];

        $pluck = isset($results['grouped_hits']) && ! empty($results['grouped_hits'])
            ? 'hits.0.document.id'
            : 'document.id';

        $objectIds = collect($hits)
            ->pluck($pluck)
            ->values()
            ->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        $scoutModels = $model->getScoutModelsByIds($builder, $objectIds);

        return $scoutModels
            ->filter(static function (Model $m) use ($objectIds): bool {
                return in_array($m->getScoutKey(), $objectIds, false);
            })
            ->sortBy(static function (Model $m) use ($objectIdPositions): int {
                return $objectIdPositions[$m->getScoutKey()];
            })
            ->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        /** @var Model&SearchableInterface $model */
        if ((int) ($results['found'] ?? 0) === 0) {
            return LazyCollection::empty();
        }

        $objectIds = collect($results['hits'] ?? [])
            ->pluck('document.id')
            ->values()
            ->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(static function (Model $m) use ($objectIds): bool {
                return in_array($m->getScoutKey(), $objectIds, false);
            })
            ->sortBy(static function (Model $m) use ($objectIdPositions): int {
                return $objectIdPositions[$m->getScoutKey()];
            })
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount(mixed $results): int
    {
        return (int) ($results['found'] ?? 0);
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @throws TypesenseClientError
     */
    public function flush(Model $model): void
    {
        /** @var Model&SearchableInterface $model */
        try {
            $this->collection($model->indexableAs())->delete();
        } catch (ObjectNotFound) {
            // The collection is already absent.
        }
    }

    /**
     * Delete every document matching the prepared Builder filters.
     */
    public function deleteByFilter(Builder $builder): void
    {
        Scout::prepareBuilder($builder, $this);

        // Reuse search parameter assembly so model-level filters cannot be bypassed during deletion.
        /** @var string $filters */
        $filters = $this->buildSearchParameters($builder, 1, null)['filter_by'];

        if (trim($filters) === '') {
            throw new InvalidArgumentException('Typesense filter deletion requires a non-empty filter.');
        }

        $index = $builder->index ?? $builder->model->indexableAs();

        $this->runOperation(
            'delete_by_filter',
            $builder,
            function () use ($filters, $index): void {
                $collection = $this->collection($index);

                try {
                    $collection->getDocuments()->delete(['filter_by' => $filters]);
                } catch (ObjectNotFound) {
                    // The collection is already absent.
                }
            },
            index: $index,
        );
    }

    /**
     * Create a search index.
     *
     * @throws NotSupportedException
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        throw new NotSupportedException('Typesense indexes are created automatically upon adding objects.');
    }

    /**
     * Delete a search index.
     *
     * @return array<string, mixed>
     * @throws TypesenseClientError
     * @throws ObjectNotFound
     */
    public function deleteIndex(string $name): array
    {
        return $this->collection($name)->delete();
    }

    /**
     * Create the model's Typesense collection.
     *
     * @param Model&SearchableInterface $model
     * @throws TypesenseClientError
     */
    protected function createCollectionFromModel(Model $model, string $collectionName): void
    {
        $modelClass = get_class($model);
        $schema = $this->getConfig("typesense.model-settings.{$modelClass}.collection-schema", []);

        if (method_exists($model, 'typesenseCollectionSchema')) {
            $schema = $model->typesenseCollectionSchema();
        }

        // Keep target selection authoritative even when a settings callback supplies a different name.
        unset($schema['name']);
        $schema = Scout::prepareIndexSettings($schema, $model, $this, $collectionName);
        $schema['name'] = $collectionName;

        try {
            $this->typesense->getCollections()->create($schema);
        } catch (ObjectAlreadyExists) {
            // Another process created the collection first.
        }
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
        $settings = $this->modelSettings($model)['embedding'] ?? null;

        if (! is_array($settings)) {
            throw new ScoutException('No Typesense embedding settings have been configured for [' . $model::class . '].');
        }

        if (! isset($settings['attribute']) || ! is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ScoutException('Typesense embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'hypervel-ai';

        if (! in_array($driver, ['hypervel-ai', 'typesense'], true)) {
            throw new ScoutException("The [{$driver}] Typesense embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNativeEmbeddings($settings)) {
            return $settings;
        }

        if (! isset($settings['dimensions'])
            || filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false
            || $settings['dimensions'] < 1) {
            throw new ScoutException('Typesense embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Typesense should generate embeddings natively.
     *
     * @param array<string, mixed> $settings
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'typesense';
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
     * Get a detached Typesense collection handle.
     */
    protected function collection(string $name): TypesenseCollection
    {
        $collections = $this->typesense->getCollections();
        $collection = $collections[$name];
        unset($collections[$name]);

        return $collection;
    }

    /**
     * Determine if model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Get the underlying Typesense client.
     */
    public function getTypesenseClient(): Typesense
    {
        return $this->typesense;
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

    /**
     * Dynamically proxy missing methods to the Typesense client instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->typesense->{$method}(...$parameters);
    }
}
