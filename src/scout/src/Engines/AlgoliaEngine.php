<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Model\Search\GetTaskResponse;
use Algolia\AlgoliaSearch\Model\Search\UpdatedAtResponse;
use BackedEnum;
use Hypervel\Context\RequestContext;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use InvalidArgumentException;

/**
 * Algolia search engine implementation (v4 client).
 *
 * Uses the Algolia\AlgoliaSearch\Api\SearchClient (v4) flat API. The v3
 * split-concrete-class layout from Laravel is collapsed into this single
 * class because Hypervel only supports Algolia v4.
 *
 * Identify headers (X-Forwarded-For, X-Algolia-UserToken) are computed per
 * request and passed as per-call request options rather than baked into
 * default client headers at construction time. This is the deliberate
 * divergence from Laravel's EngineManager::defaultAlgoliaHeaders() which
 * cannot work correctly under a persistent-worker model where the engine
 * instance is cached across requests.
 */
class AlgoliaEngine extends Engine implements DeletesByFilter, UpdatesIndexSettings
{
    /**
     * Create a new AlgoliaEngine instance.
     */
    public function __construct(
        protected AlgoliaSearchClient $algolia,
        protected bool $softDelete = false,
        protected bool $identify = false
    ) {
    }

    /**
     * Update the given models in the search index.
     *
     * @param EloquentCollection<int, Model> $models
     * @throws AlgoliaException
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $firstModel = $models->first();
        $index = $firstModel->indexableAs();

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
                ['objectID' => $model->getScoutKey()],
            );

            return Scout::prepareSearchableDocument($document, $model, $this);
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($objects)) {
            $this->algolia->saveObjects($index, $objects);
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

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($firstModel->getScoutKeyName())
            : $models->map(fn (Model $model) => $model->getScoutKey());

        $this->algolia->deleteObjects($firstModel->indexableAs(), $keys->all());
    }

    /**
     * Perform a search against the engine.
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter([
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform a paginated search against the engine.
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return $this->performSearch($builder, [
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $options = array_merge($builder->options, $options);
        $filters = $this->combineFilters($options['filters'] ?? '', $this->filters($builder));

        if ($filters === '') {
            unset($options['filters']);
        } else {
            $options['filters'] = $filters;
        }

        if ($builder->callback !== null) {
            return call_user_func(
                $builder->callback,
                $this->algolia,
                $builder->query,
                $options,
            );
        }

        $queryParams = array_merge(['query' => $builder->query], $options);

        $requestOptions = [];
        if ($this->identify) {
            $headers = $this->identifyHeaders();
            if ($headers !== []) {
                $requestOptions['headers'] = $headers;
            }
        }

        return $this->algolia->searchSingleIndex(
            $builder->index ?? $builder->model->searchableAs(),
            $queryParams,
            $requestOptions,
        );
    }

    /**
     * Build per-request identify headers for the current request.
     *
     * Reads the current coroutine-local request directly from RequestContext
     * rather than via the request() helper. Matches the pattern used by
     * CookieJar and other Hypervel code that needs request state without
     * depending on HttpServiceProvider being registered. Returns an empty
     * array when no request is in context (e.g. CLI, queue jobs), the IP
     * is missing or private/reserved, or no authenticated user is present.
     *
     * @return array<string, string>
     */
    protected function identifyHeaders(): array
    {
        $headers = [];
        $request = RequestContext::getOrNull();

        if ($request === null) {
            return $headers;
        }

        $ip = $request->ip();
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $headers['X-Forwarded-For'] = $ip;
        }

        $user = $request->user();
        if ($user !== null && method_exists($user, 'getKey')) {
            $key = $user->getKey();
            if ($key !== null) {
                $headers['X-Algolia-UserToken'] = (string) $key;
            }
        }

        return $headers;
    }

    /**
     * Get the filter expression for the query.
     */
    protected function filters(Builder $builder): string
    {
        $wheres = collect($builder->wheres)
            ->map(function (array $where): string {
                $field = $where['field'];
                $operator = $where['operator'];
                $value = $where['value'];

                if (! in_array($operator, ['=', '!=', '<', '>', '<=', '>='], true)) {
                    throw new InvalidArgumentException("Unsupported Algolia filter operator [{$operator}].");
                }

                if ($value instanceof BackedEnum) {
                    $value = $value->value;
                }

                if ($value === null) {
                    throw new InvalidArgumentException('Algolia filters do not support null values.');
                }

                if (is_float($value) && ! is_finite($value)) {
                    throw new InvalidArgumentException('Algolia filters require finite numeric values.');
                }

                if (in_array($operator, ['<', '>', '<=', '>='], true)) {
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    } elseif (is_int($value) || is_float($value)) {
                        $value = (string) $value;
                    } elseif (! is_string($value)
                        || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
                        throw new InvalidArgumentException('Algolia ordering filters require a numeric value.');
                    }

                    return $field . $operator . $value;
                }

                if (is_string($value) || is_bool($value)) {
                    $value = $this->formatFilterValue($value);

                    if ($operator === '!=') {
                        return 'NOT ' . $field . ':' . $value;
                    }

                    $operator = ':';
                } elseif ($operator === '=') {
                    $operator = ':';
                    $value = "'{$value}'";
                }

                return $field . $operator . $value;
            })
            ->values();

        $whereIns = collect($builder->whereIns)
            ->map(function (array $values, string $key): string {
                if ($values === []) {
                    return '0:1';
                }

                return '(' . collect($values)
                    ->map(fn (mixed $value): string => $key . ':' . $this->formatFilterValue($value))
                    ->implode(' OR ') . ')';
            })
            ->values();

        $whereNotIns = collect($builder->whereNotIns)
            ->map(function (array $values, string $key): string {
                if ($values === []) {
                    return '';
                }

                return collect($values)
                    ->map(fn (mixed $value): string => 'NOT ' . $key . ':' . $this->formatFilterValue($value))
                    ->implode(' AND ');
            })
            ->values();

        return $wheres->merge($whereIns)->merge($whereNotIns)->filter()->implode(' AND ');
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

        return "({$applicationFilters}) AND ({$builderFilters})";
    }

    /**
     * Format the given value for use in an Algolia filter.
     */
    protected function formatFilterValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        return collect($results['hits'])->pluck('objectID')->values();
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        /** @var Model&SearchableInterface $model */
        if (count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck('objectID')->values()->all();

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

        $objectIds = collect($results['hits'])->pluck('objectID')->values()->all();

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
        return $results['nbHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        /** @var Model&SearchableInterface $model */
        $this->algolia->clearObjects($model->indexableAs());
    }

    /**
     * Delete every document matching the prepared Builder filters.
     */
    public function deleteByFilter(Builder $builder): void
    {
        Scout::prepareBuilder($builder, $this);

        $filters = $this->combineFilters($builder->options['filters'] ?? '', $this->filters($builder));

        if (trim($filters) === '') {
            throw new InvalidArgumentException('Algolia filter deletion requires a non-empty filter.');
        }

        $index = $builder->index ?? $builder->model->indexableAs();

        try {
            /** @var array{taskID: int}|UpdatedAtResponse $response */
            $response = $this->algolia->deleteBy($index, ['filters' => $filters]);
        } catch (NotFoundException) {
            // The index is already absent.
            return;
        }

        /** @var null|array<string, mixed>|GetTaskResponse $task */
        $task = $this->algolia->waitForTask($index, $response['taskID']);

        if (($task['status'] ?? null) !== 'published') {
            throw new ScoutException('Algolia filter deletion did not complete successfully.');
        }
    }

    /**
     * Create a search index.
     *
     * @throws NotSupportedException
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        throw new NotSupportedException('Algolia indexes are created automatically upon adding objects.');
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex(string $name): mixed
    {
        return $this->algolia->deleteIndex($name);
    }

    /**
     * Delete all search indexes, optionally scoped by name prefix.
     *
     * When $prefix is non-empty, only indexes whose name starts with $prefix
     * are deleted. When $prefix is null (or empty string, which str_starts_with
     * matches against every string), every index in the Algolia application
     * is deleted.
     *
     * @return array<int, mixed> Task/response objects from each deleteIndex call
     */
    public function deleteAllIndexes(?string $prefix = null): array
    {
        $responses = [];
        $indices = $this->algolia->listIndices();

        foreach ($indices['items'] ?? [] as $index) {
            $name = $index['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            if ($prefix === null || str_starts_with($name, $prefix)) {
                $responses[] = $this->algolia->deleteIndex($name);
            }
        }

        return $responses;
    }

    /**
     * Update the index settings for the given index.
     */
    public function updateIndexSettings(string $name, array $settings = []): void
    {
        $this->algolia->setSettings($name, $settings);
    }

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function configureSoftDeleteFilter(array $settings = []): array
    {
        $settings['attributesForFaceting'][] = 'filterOnly(__soft_deleted)';

        return $settings;
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Dynamically call the Algolia client instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->algolia->{$method}(...$parameters);
    }
}
