<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use stdClass;
use Typesense\Client as Typesense;
use Typesense\Collection as TypesenseCollection;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Typesense search engine implementation.
 *
 * Provides full-text search using Typesense as the backend.
 */
class TypesenseEngine extends Engine
{
    /**
     * The specified search parameters.
     *
     * @var array<string, mixed>
     */
    protected array $searchParameters = [];

    /**
     * The maximum number of results that can be fetched per page.
     */
    private int $maxPerPage = 250;

    /**
     * Create a new TypesenseEngine instance.
     */
    public function __construct(
        protected Typesense $typesense,
        protected int $maxTotalResults
    ) {
    }

    /**
     * Update the given models in the search index.
     *
     * @param EloquentCollection<int, Model&SearchableInterface> $models
     * @throws TypesenseClientError
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model&SearchableInterface $firstModel */
        $firstModel = $models->first();

        $collection = $this->getOrCreateCollectionFromModel($firstModel);

        if ($this->usesSoftDelete($firstModel) && $this->getConfig('soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function (Model $model): ?array {
            /** @var Model&SearchableInterface $model */
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                return null;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
            );
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($objects)) {
            $this->importDocuments($collection, $objects);
        }
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
        $data = new stdClass();

        $data->code = $document['code'] ?? 0;
        $data->success = $document['success'];
        $data->error = $document['error'] ?? null;
        $data->document = json_decode($document['document'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Remove the given models from the search index.
     *
     * @param EloquentCollection<int, Model&SearchableInterface> $models
     * @throws TypesenseClientError
     */
    public function delete(EloquentCollection $models): void
    {
        $models->each(function (Model $model): void {
            /** @var Model&SearchableInterface $model */
            $this->deleteDocument(
                $this->getOrCreateCollectionFromModel($model, null, false),
                $model->getScoutKey()
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
            $document->retrieve();

            return $document->delete();
        } catch (ObjectNotFound) {
            // Document already gone, nothing to delete
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
        $maxInt = 4294967295;

        $page = max(1, $page);
        $perPage = max(1, min($perPage, $this->maxPerPage, $this->maxTotalResults));

        if ($page * $perPage > $maxInt) {
            $page = (int) floor($maxInt / $perPage);
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
        $documents = $this->getOrCreateCollectionFromModel(
            $builder->model,
            $builder->index,
            false,
        )->getDocuments();

        if ($builder->callback !== null) {
            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        try {
            return $documents->search($options);
        } catch (ObjectNotFound) {
            $this->getOrCreateCollectionFromModel($builder->model, $builder->index, true);

            return $documents->search($options);
        }
    }

    /**
     * Perform a paginated search on the engine.
     *
     * @return array{hits: array<mixed>, found: int, out_of: int, page: int, request_params: array<string, mixed>}
     * @throws TypesenseClientError
     */
    protected function performPaginatedSearch(Builder $builder): array
    {
        $page = 1;
        $limit = min($builder->limit ?? $this->maxPerPage, $this->maxPerPage, $this->maxTotalResults);
        $remainingResults = min($builder->limit ?? $this->maxTotalResults, $this->maxTotalResults);

        $results = new Collection();
        $totalFound = 0;

        while ($remainingResults > 0) {
            /** @var array{hits?: array<mixed>, found?: int} $searchResults */
            $searchResults = $this->performSearch(
                $builder,
                $this->buildSearchParameters($builder, $page, $limit)
            );

            $results = $results->concat($searchResults['hits'] ?? []);

            if ($page === 1) {
                $totalFound = $searchResults['found'] ?? 0;
            }

            $remainingResults -= $limit;
            ++$page;

            if (count($searchResults['hits'] ?? []) < $limit) {
                break;
            }
        }

        return [
            'hits' => $results->all(),
            'found' => $results->count(),
            'out_of' => $totalFound,
            'page' => 1,
            'request_params' => $this->buildSearchParameters($builder, 1, $builder->limit ?? $this->maxPerPage),
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
            'filter_by' => $this->filters($builder),
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

        if (! empty($builder->orders)) {
            if (! empty($parameters['sort_by'])) {
                $parameters['sort_by'] .= ',';
            } else {
                $parameters['sort_by'] = '';
            }

            $parameters['sort_by'] .= $this->parseOrderBy($builder->orders);
        }

        return $parameters;
    }

    /**
     * Prepare the filters for a given search query.
     */
    protected function filters(Builder $builder): string
    {
        $whereFilter = collect($builder->wheres)
            ->map(fn (mixed $value, string $key): string => $this->parseWhereFilter($this->parseFilterValue($value), $key))
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
     * @param array<mixed>|bool|float|int|string $value
     * @return array<mixed>|float|int|string
     */
    protected function parseFilterValue(array|string|bool|int|float $value): array|string|int|float
    {
        if (is_array($value)) {
            return array_map([$this, 'parseFilterValue'], $value);
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
    protected function parseWhereFilter(array|string|int|float $value, string $key): string
    {
        return is_array($value)
            ? sprintf('%s:%s', $key, implode('', $value))
            : sprintf('%s:=%s', $key, $value);
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
     * @param Model&SearchableInterface $model
     * @return EloquentCollection<int, Model&SearchableInterface>
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
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

        /** @var EloquentCollection<int, Model&SearchableInterface> $scoutModels */
        $scoutModels = $model->getScoutModelsByIds($builder, $objectIds);

        return $scoutModels
            ->filter(static function (Model $m) use ($objectIds): bool {
                /** @var Model&SearchableInterface $m */
                return in_array($m->getScoutKey(), $objectIds, false);
            })
            ->sortBy(static function (Model $m) use ($objectIdPositions): int {
                /** @var Model&SearchableInterface $m */
                return $objectIdPositions[$m->getScoutKey()];
            })
            ->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param Model&SearchableInterface $model
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
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
                /** @var Model&SearchableInterface $m */
                return in_array($m->getScoutKey(), $objectIds, false);
            })
            ->sortBy(static function (Model $m) use ($objectIdPositions): int {
                /** @var Model&SearchableInterface $m */
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
     * @param Model&SearchableInterface $model
     * @throws TypesenseClientError
     */
    public function flush(Model $model): void
    {
        $this->getOrCreateCollectionFromModel($model)->delete();
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
        return $this->typesense->getCollections()->{$name}->delete();
    }

    /**
     * Get collection from model or create new one.
     *
     * @param Model&SearchableInterface $model
     * @throws TypesenseClientError
     */
    protected function getOrCreateCollectionFromModel(
        Model $model,
        ?string $collectionName = null,
        bool $indexOperation = true
    ): TypesenseCollection {
        if (! $indexOperation) {
            $collectionName = $collectionName ?? $model->searchableAs();
        } else {
            $collectionName = $model->indexableAs();
        }

        $collection = $this->typesense->getCollections()->{$collectionName};

        if (! $indexOperation) {
            return $collection;
        }

        // Determine if the collection exists in Typesense
        try {
            $collection->retrieve();
            $collection->setExists(true);

            return $collection;
        } catch (TypesenseClientError) {
            // Collection doesn't exist, will create it
        }

        $modelClass = get_class($model);
        $schema = $this->getConfig("typesense.model-settings.{$modelClass}.collection-schema", []);

        if (method_exists($model, 'typesenseCollectionSchema')) {
            $schema = $model->typesenseCollectionSchema();
        }

        if (! isset($schema['name'])) {
            $schema['name'] = $model->searchableAs();
        }

        try {
            $this->typesense->getCollections()->create($schema);
        } catch (ObjectAlreadyExists) {
            // Collection already exists
        }

        $collection->setExists(true);

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
