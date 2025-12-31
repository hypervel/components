<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\Engine;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;

use function Hypervel\Support\class_uses_recursive;
use function Hypervel\Support\collect;

/**
 * Meilisearch search engine implementation.
 *
 * Provides full-text search using Meilisearch as the backend.
 */
class MeilisearchEngine extends Engine implements UpdatesIndexSettings
{
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
     * @throws ApiException
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->indexableAs());

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function ($model) {
            $searchableData = $model->toSearchableArray();

            if (empty($searchableData)) {
                return null;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
                [$model->getScoutKeyName() => $model->getScoutKey()],
            );
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($objects)) {
            $index->addDocuments($objects, $models->first()->getScoutKeyName());
        }
    }

    /**
     * Remove the given models from the search index.
     */
    public function delete(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->indexableAs());

        $keys = $models->map->getScoutKey()->values()->all();

        $index->deleteDocuments($keys);
    }

    /**
     * Perform a search against the engine.
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder),
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
            'filter' => $this->filters($builder),
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
            ->map(function ($value, $key) {
                if (is_bool($value)) {
                    return sprintf('%s=%s', $key, $value ? 'true' : 'false');
                }

                if ($value === null) {
                    return sprintf('%s IS NULL', $key);
                }

                return is_numeric($value)
                    ? sprintf('%s=%s', $key, $value)
                    : sprintf('%s="%s"', $key, $value);
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
                    collect($values)->map(function ($value) {
                        if (is_bool($value)) {
                            return $value ? 'true' : 'false';
                        }

                        return filter_var($value, FILTER_VALIDATE_INT) !== false
                            ? (string) $value
                            : sprintf('"%s"', $value);
                    })->implode(', ')
                ));
            }
        }

        return $filters->values()->implode(' AND ');
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
        if ($results === null || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])
            ->pluck($model->getScoutKeyName())
            ->values()
            ->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (str_starts_with($key, '_')) {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])
            ->pluck($model->getScoutKeyName())
            ->values()
            ->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (str_starts_with($key, '_')) {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
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
        $index = $this->meilisearch->index($model->indexableAs());

        $index->deleteAllDocuments();
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
        } catch (ApiException) {
            $index = null;
        }

        if ($index?->getUid() !== null) {
            return $index;
        }

        return $this->meilisearch->createIndex($name, $options);
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
     * Delete all search indexes.
     *
     * @return array<mixed>
     */
    public function deleteAllIndexes(): array
    {
        $tasks = [];
        $limit = 1000000;

        $query = new IndexesQuery();
        $query->setLimit($limit);

        $indexes = $this->meilisearch->getIndexes($query);

        foreach ($indexes->getResults() as $index) {
            $tasks[] = $index->delete();
        }

        return $tasks;
    }

    /**
     * Generate a tenant token for frontend direct search.
     *
     * Tenant tokens allow secure, scoped searches directly from the frontend
     * without exposing the admin API key.
     *
     * @param array<string, array{filter?: string}> $searchRules Rules per index
     * @param DateTimeImmutable|null $expiresAt Token expiration
     */
    public function generateTenantToken(
        array $searchRules,
        ?string $apiKeyUid = null,
        ?\DateTimeImmutable $expiresAt = null
    ): string {
        return $this->meilisearch->generateTenantToken(
            $apiKeyUid ?? $this->getDefaultApiKeyUid(),
            $searchRules,
            [
                'expiresAt' => $expiresAt,
            ]
        );
    }

    /**
     * Get the default API key UID for tenant token generation.
     */
    protected function getDefaultApiKeyUid(): string
    {
        // The API key's UID is typically the first 8 chars of the key
        // This should be configured or retrieved from Meilisearch
        $keys = $this->meilisearch->getKeys();

        foreach ($keys->getResults() as $key) {
            if (in_array('search', $key->getActions()) || in_array('*', $key->getActions())) {
                return $key->getUid();
            }
        }

        throw new \RuntimeException('No valid API key found for tenant token generation.');
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
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
        return $this->meilisearch->$method(...$parameters);
    }
}
