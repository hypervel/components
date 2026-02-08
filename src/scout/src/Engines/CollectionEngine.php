<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engine;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Str;

/**
 * In-memory search engine using database queries and Collection filtering.
 *
 * Useful for testing without requiring an external search service.
 */
class CollectionEngine extends Engine
{
    /**
     * Update the given models in the search index.
     */
    public function update(EloquentCollection $models): void
    {
        // No-op - data lives in the database
    }

    /**
     * Remove the given models from the search index.
     */
    public function delete(EloquentCollection $models): void
    {
        // No-op - data lives in the database
    }

    /**
     * Perform a search against the engine.
     *
     * @return array{results: array<Model>, total: int}
     */
    public function search(Builder $builder): mixed
    {
        $models = $this->searchModels($builder);

        if ($builder->limit !== null) {
            $models = $models->take($builder->limit);
        }

        /** @var array<Model> $results */
        $results = $models->all();

        return [
            'results' => $results,
            'total' => count($models),
        ];
    }

    /**
     * Perform a paginated search against the engine.
     *
     * @return array{results: array<Model>, total: int}
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $models = $this->searchModels($builder);

        /** @var array<Model> $results */
        $results = $models->forPage($page, $perPage)->all();

        return [
            'results' => $results,
            'total' => count($models),
        ];
    }

    /**
     * Get the Eloquent models for the given builder.
     */
    protected function searchModels(Builder $builder): EloquentCollection
    {
        $query = $builder->model->query()
            ->when($builder->callback !== null, function ($query) use ($builder) {
                call_user_func($builder->callback, $query, $builder, $builder->query);
            })
            ->when($builder->callback === null && count($builder->wheres) > 0, function ($query) use ($builder) {
                foreach ($builder->wheres as $key => $value) {
                    if ($key !== '__soft_deleted') {
                        $query->where($key, $value);
                    }
                }
            })
            ->when($builder->callback === null && count($builder->whereIns) > 0, function ($query) use ($builder) {
                foreach ($builder->whereIns as $key => $values) {
                    $query->whereIn($key, $values);
                }
            })
            ->when($builder->callback === null && count($builder->whereNotIns) > 0, function ($query) use ($builder) {
                foreach ($builder->whereNotIns as $key => $values) {
                    $query->whereNotIn($key, $values);
                }
            })
            ->when(count($builder->orders) > 0, function ($query) use ($builder) {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            }, function (EloquentBuilder $query) use ($builder) {
                $query->orderBy(
                    $builder->model->qualifyColumn($builder->model->getScoutKeyName()),
                    'desc'
                );
            });

        /** @var EloquentCollection<int, Model&SearchableInterface> $models */
        $models = $this->ensureSoftDeletesAreHandled($builder, $query)
            ->get()
            ->values();

        if ($models->isEmpty()) {
            return $models;
        }

        /** @var Model&SearchableInterface $firstModel */
        $firstModel = $models->first();

        /** @var EloquentCollection<int, Model&SearchableInterface> $searchableModels */
        $searchableModels = $firstModel->makeSearchableUsing($models);

        return $searchableModels
            ->filter(function ($model) use ($builder) {
                /** @var Model&SearchableInterface $model */
                if (! $model->shouldBeSearchable()) {
                    return false;
                }

                if (! $builder->query) {
                    return true;
                }

                $searchables = $model->toSearchableArray();

                foreach ($searchables as $value) {
                    if (! is_scalar($value)) {
                        $value = json_encode($value);
                    }

                    if (Str::contains(Str::lower((string) $value), Str::lower($builder->query))) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Ensure that soft delete handling is properly applied to the query.
     *
     * The withTrashed/onlyTrashed/withoutTrashed methods are added dynamically
     * by SoftDeletingScope. We guard these calls with runtime checks for SoftDeletes
     * usage, making them safe but not statically analyzable.
     */
    protected function ensureSoftDeletesAreHandled(Builder $builder, EloquentBuilder $query): EloquentBuilder
    {
        if (Arr::get($builder->wheres, '__soft_deleted') === 0) {
            /* @phpstan-ignore method.notFound (SoftDeletingScope adds this method) */
            return $query->withoutTrashed();
        }

        if (Arr::get($builder->wheres, '__soft_deleted') === 1) {
            /* @phpstan-ignore method.notFound (SoftDeletingScope adds this method) */
            return $query->onlyTrashed();
        }

        if (in_array(SoftDeletes::class, class_uses_recursive(get_class($builder->model)))
            && $this->getScoutConfig('soft_delete', false)
        ) {
            /* @phpstan-ignore method.notFound (SoftDeletingScope adds this method) */
            return $query->withTrashed();
        }

        return $query;
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        /** @var array<int, Model&SearchableInterface> $resultModels */
        $resultModels = array_values($results['results']);

        if (count($resultModels) === 0) {
            return collect();
        }

        return collect($resultModels)->pluck($resultModels[0]->getScoutKeyName());
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param Model&SearchableInterface $model
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        $results = $results['results'];

        if (count($results) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results)
            ->pluck($model->getScoutKeyName())
            ->values()
            ->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        /** @var EloquentCollection<int, Model&SearchableInterface> $scoutModels */
        $scoutModels = $model->getScoutModelsByIds($builder, $objectIds);

        return $scoutModels
            ->filter(fn ($m) => in_array($m->getScoutKey(), $objectIds))
            ->sortBy(fn ($m) => $objectIdPositions[$m->getScoutKey()])
            ->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param Model&SearchableInterface $model
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        $results = $results['results'];

        if (count($results) === 0) {
            return LazyCollection::empty();
        }

        $objectIds = collect($results)
            ->pluck($model->getScoutKeyName())
            ->values()
            ->all();

        /** @var array<int|string> $objectIds */
        $objectIdPositions = array_flip($objectIds);

        /** @var LazyCollection<int, Model&SearchableInterface> $cursor */
        $cursor = $model->queryScoutModelsByIds($builder, $objectIds)->cursor();

        return $cursor
            ->filter(fn ($m) => in_array($m->getScoutKey(), $objectIds))
            ->sortBy(fn ($m) => $objectIdPositions[$m->getScoutKey()])
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount(mixed $results): int
    {
        return $results['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        // No-op - data lives in the database
    }

    /**
     * Create a search index.
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        return null;
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex(string $name): mixed
    {
        return null;
    }

    /**
     * Get a Scout configuration value.
     */
    protected function getScoutConfig(string $key, mixed $default = null): mixed
    {
        return ApplicationContext::getContainer()
            ->get('config')
            ->get("scout.{$key}", $default);
    }
}
