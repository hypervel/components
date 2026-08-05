<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use BackedEnum;
use DateTimeInterface;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use InvalidArgumentException;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;

/**
 * Meilisearch search engine implementation.
 *
 * Provides full-text search using Meilisearch as the backend.
 */
class MeilisearchEngine extends Engine implements DeletesByFilter, UpdatesIndexSettings
{
    /**
     * The maximum time to wait for a filtered deletion task.
     */
    protected const FILTER_DELETE_TIMEOUT_IN_MS = 500_000;

    /**
     * The interval between filtered deletion task checks.
     */
    protected const FILTER_DELETE_INTERVAL_IN_MS = 5_000;

    /**
     * Create a new MeilisearchEngine instance.
     */
    public function __construct(
        protected MeilisearchClient $meilisearch,
        protected bool $softDelete = false
    ) {
    }

    /**
     * Update the given models in the search index.
     *
     * @param EloquentCollection<int, Model> $models
     * @throws ApiException
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $firstModel = $models->first();
        $index = $this->meilisearch->index($firstModel->indexableAs());

        if ($this->usesSoftDelete($firstModel) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function (Model $model) {
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                return null;
            }

            $document = array_merge(
                $searchableData,
                $model->scoutMetadata(),
                [$model->getScoutKeyName() => $model->getScoutKey()],
            );

            return Scout::prepareSearchableDocument($document, $model, $this);
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($objects)) {
            $index->addDocuments($objects, $firstModel->getScoutKeyName());
        }
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
        $firstModel = $models->first();
        $index = $this->meilisearch->index($firstModel->indexableAs());

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($firstModel->getScoutKeyName())->values()->all()
            : $models->map(fn (Model $model) => $model->getScoutKey())->values()->all();

        $index->deleteDocuments($keys);
    }

    /**
     * Perform a search against the engine.
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter([
            'hitsPerPage' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    /**
     * Perform a paginated search against the engine.
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return $this->performSearch($builder, array_filter([
            'hitsPerPage' => $perPage,
            'page' => $page,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    /**
     * Perform the given search on the engine.
     */
    protected function performSearch(Builder $builder, array $searchParams = []): mixed
    {
        $meilisearch = $this->meilisearch->index($builder->index ?? $builder->model->searchableAs());

        $searchParams = array_merge($builder->options, $searchParams);
        $filter = $this->combineFilters($searchParams['filter'] ?? null, $this->filters($builder));

        if ($filter === '') {
            unset($searchParams['filter']);
        } else {
            $searchParams['filter'] = $filter;
        }

        if (array_key_exists('attributesToRetrieve', $searchParams)) {
            $searchParams['attributesToRetrieve'] = array_merge(
                [$builder->model->getScoutKeyName()],
                $searchParams['attributesToRetrieve'],
            );
        }

        if ($builder->callback !== null) {
            $result = call_user_func(
                $builder->callback,
                $meilisearch,
                $builder->query,
                $searchParams
            );

            return $result instanceof SearchResult ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    /**
     * Get the filter string for the query.
     */
    protected function filters(Builder $builder): string
    {
        $filters = collect($builder->wheres)
            ->map(function (array $where): string {
                $field = $where['field'];
                $operator = $where['operator'];
                $value = $where['value'];

                if ($value instanceof BackedEnum) {
                    $value = $value->value;
                }

                if (is_bool($value)) {
                    return sprintf('%s%s%s', $field, $operator, $value ? 'true' : 'false');
                }

                if ($value === null) {
                    return sprintf('%s %s', $field, $operator === '!=' ? 'IS NOT NULL' : 'IS NULL');
                }

                return is_numeric($value)
                    ? sprintf('%s%s%s', $field, $operator, $value)
                    : sprintf('%s%s"%s"', $field, $operator, addcslashes((string) $value, '"\\'));
            });

        $whereInOperators = [
            'whereIns' => 'IN',
            'whereNotIns' => 'NOT IN',
        ];

        foreach ($whereInOperators as $property => $operator) {
            foreach ($builder->{$property} as $key => $values) {
                $filters->push(sprintf(
                    '%s %s [%s]',
                    $key,
                    $operator,
                    collect($values)->map(function (mixed $value): string {
                        if ($value instanceof BackedEnum) {
                            $value = $value->value;
                        }

                        if (is_bool($value)) {
                            return $value ? 'true' : 'false';
                        }

                        return filter_var($value, FILTER_VALIDATE_INT) !== false
                            ? (string) $value
                            : sprintf('"%s"', addcslashes((string) $value, '"\\'));
                    })->implode(', ')
                ));
            }
        }

        return $filters->values()->implode(' AND ');
    }

    /**
     * Combine application and Builder filters without changing their precedence.
     *
     * @param null|array<mixed>|string $applicationFilters
     *
     * @return array<mixed>|string
     */
    protected function combineFilters(array|string|null $applicationFilters, string $builderFilters): string|array
    {
        if ($applicationFilters === null
            || $applicationFilters === []
            || (is_string($applicationFilters) && trim($applicationFilters) === '')) {
            return $builderFilters;
        }

        if ($builderFilters === '') {
            return $applicationFilters;
        }

        return is_array($applicationFilters)
            ? [...$applicationFilters, $builderFilters]
            : "({$applicationFilters}) AND ({$builderFilters})";
    }

    /**
     * Get the sort array for the query.
     *
     * @return array<string>
     */
    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        return collect($builder->orders)
            ->map(fn (array $order) => $order['column'] . ':' . $order['direction'])
            ->toArray();
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        if (count($results['hits']) === 0) {
            return collect();
        }

        $hits = collect($results['hits']);
        $key = key($hits->first());

        return $hits->pluck($key)->values();
    }

    /**
     * Pluck the given results with the given primary key name.
     */
    public function mapIdsFrom(mixed $results, string $key): Collection
    {
        return count($results['hits']) === 0
            ? collect()
            : collect($results['hits'])->pluck($key)->values();
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     */
    public function keys(Builder $builder): Collection
    {
        $scoutKey = $builder->model->getScoutKeyName();

        return $this->mapIdsFrom($this->search($builder), $scoutKey);
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        /** @var Model&SearchableInterface $model */
        if ($results === null || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])
            ->pluck($model->getScoutKeyName())
            ->values()
            ->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        $scoutModels = $model->getScoutModelsByIds($builder, $objectIds);

        // Search engines serialize numeric Scout keys as strings.
        $mapped = $scoutModels
            ->filter(fn ($m) => in_array($m->getScoutKey(), $objectIds, false))
            ->map(function ($m) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$m->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (str_starts_with($key, '_')) {
                        $m->withScoutMetadata($key, $value);
                    }
                }

                return $m;
            })
            ->sortBy(fn ($m) => $objectIdPositions[$m->getScoutKey()])
            ->values();

        return $model->newCollection($mapped->all());
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        /** @var Model&SearchableInterface $model */
        if (count($results['hits']) === 0) {
            return LazyCollection::empty();
        }

        $objectIds = collect($results['hits'])
            ->pluck($model->getScoutKeyName())
            ->values()
            ->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        $cursor = $model->queryScoutModelsByIds($builder, $objectIds)->cursor();

        return $cursor
            ->filter(fn ($m) => in_array($m->getScoutKey(), $objectIds, false))
            ->map(function ($m) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$m->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (str_starts_with($key, '_')) {
                        $m->withScoutMetadata($key, $value);
                    }
                }

                return $m;
            })
            ->sortBy(fn ($m) => $objectIdPositions[$m->getScoutKey()])
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount(mixed $results): int
    {
        return $results['totalHits'] ?? $results['estimatedTotalHits'] ?? 0;
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        /** @var Model&SearchableInterface $model */
        $index = $this->meilisearch->index($model->indexableAs());

        $index->deleteAllDocuments();
    }

    /**
     * Delete every document matching the prepared Builder filters.
     */
    public function deleteByFilter(Builder $builder): void
    {
        Scout::prepareBuilder($builder, $this);

        $filter = $this->combineFilters($builder->options['filter'] ?? null, $this->filters($builder));

        if ($filter === '') {
            throw new InvalidArgumentException('Meilisearch filter deletion requires a non-empty filter.');
        }

        $index = $this->meilisearch->index($builder->index ?? $builder->model->indexableAs());
        $task = $index->deleteDocuments(['filter' => $filter]);

        // Bulk purges favor bounded service load over the SDK's interactive polling cadence.
        $result = $this->meilisearch->waitForTask(
            $task['taskUid'],
            self::FILTER_DELETE_TIMEOUT_IN_MS,
            self::FILTER_DELETE_INTERVAL_IN_MS,
        );

        if (($result['status'] ?? null) === 'failed'
            && ($result['error']['code'] ?? null) === 'index_not_found') {
            // Meilisearch reports an already-absent index through its asynchronous task.
            return;
        }

        if (($result['status'] ?? null) !== 'succeeded') {
            throw new ScoutException('Meilisearch filter deletion did not complete successfully.');
        }
    }

    /**
     * Create a search index.
     *
     * @throws ApiException
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        try {
            $index = $this->meilisearch->getIndex($name);
        } catch (ApiException $exception) {
            if ($exception->httpStatus !== 404) {
                throw $exception;
            }

            $index = null;
        }

        if ($index?->getUid() !== null) {
            return $index;
        }

        try {
            return $this->meilisearch->createIndex($name, $options);
        } catch (ApiException $exception) {
            if ($exception->errorCode !== 'index_already_exists') {
                throw $exception;
            }

            return $this->meilisearch->index($name);
        }
    }

    /**
     * Update the index settings for the given index.
     */
    public function updateIndexSettings(string $name, array $settings = []): void
    {
        $index = $this->meilisearch->index($name);

        $index->updateSettings(Arr::except($settings, 'embedders'));

        if (! empty($settings['embedders'])) {
            $index->updateEmbedders($settings['embedders']);
        }
    }

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @return array<string, mixed>
     */
    public function configureSoftDeleteFilter(array $settings = []): array
    {
        $settings['filterableAttributes'][] = '__soft_deleted';

        return $settings;
    }

    /**
     * Delete a search index.
     *
     * @throws ApiException
     */
    public function deleteIndex(string $name): mixed
    {
        return $this->meilisearch->deleteIndex($name);
    }

    /**
     * Delete all search indexes, optionally scoped by uid prefix.
     *
     * When $prefix is non-empty, only indexes whose uid starts with $prefix
     * are deleted. When $prefix is null (or empty string, which str_starts_with
     * matches against every string), every index on the Meilisearch server
     * is deleted.
     *
     * @return array<mixed>
     */
    public function deleteAllIndexes(?string $prefix = null): array
    {
        $tasks = [];
        $limit = 1000000;

        $query = new IndexesQuery;
        $query->setLimit($limit);

        $indexes = $this->meilisearch->getIndexes($query);

        foreach ($indexes->getResults() as $index) {
            $uid = $index->getUid();

            if ($uid === null) {
                continue;
            }

            if ($prefix === null || str_starts_with($uid, $prefix)) {
                $tasks[] = $index->delete();
            }
        }

        return $tasks;
    }

    /**
     * Generate a tenant token for frontend direct search.
     *
     * Tenant tokens allow secure, scoped searches directly from the frontend
     * without exposing the admin API key. All tenants share a single index,
     * with data isolation enforced at query time via embedded filters.
     *
     * @param array<string, array{filter?: array<mixed>|string}> $searchRules Rules per index
     *
     * @see https://www.meilisearch.com/blog/multi-tenancy-guide
     */
    public function generateTenantToken(
        array $searchRules,
        string $apiKeyUid,
        string $apiKey,
        ?DateTimeInterface $expiresAt = null
    ): string {
        if ($apiKeyUid === '') {
            throw new InvalidArgumentException('Meilisearch tenant tokens require a non-empty API key UID.');
        }

        // The SDK substitutes its client key for an empty option, which could sign for the wrong parent key.
        if ($apiKey === '') {
            throw new InvalidArgumentException('Meilisearch tenant tokens require a non-empty API key.');
        }

        return $this->meilisearch->generateTenantToken(
            $apiKeyUid,
            $searchRules,
            [
                'apiKey' => $apiKey,
                'expiresAt' => $expiresAt,
            ]
        );
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Get the underlying Meilisearch client.
     */
    public function getMeilisearchClient(): MeilisearchClient
    {
        return $this->meilisearch;
    }

    /**
     * Dynamically call the Meilisearch client instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->meilisearch->{$method}(...$parameters);
    }
}
