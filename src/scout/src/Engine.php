<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

/**
 * Abstract base class for search engine implementations.
 *
 * Engines handle the actual indexing and searching operations with external
 * search services like Meilisearch, or in-memory for testing.
 */
abstract class Engine
{
    /**
     * Update the given models in the search index.
     */
    abstract public function update(EloquentCollection $models): void;

    /**
     * Remove the given models from the search index.
     */
    abstract public function delete(EloquentCollection $models): void;

    /**
     * Perform a search against the engine.
     */
    abstract public function search(Builder $builder): mixed;

    /**
     * Perform a paginated search against the engine.
     */
    abstract public function paginate(Builder $builder, int $perPage, int $page): mixed;

    /**
     * Pluck and return the primary keys of the given results.
     */
    abstract public function mapIds(mixed $results): Collection;

    /**
     * Map the given results to instances of the given model.
     */
    abstract public function map(Builder $builder, mixed $results, Model $model): EloquentCollection;

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    abstract public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection;

    /**
     * Get the total count from a raw result returned by the engine.
     */
    abstract public function getTotalCount(mixed $results): int;

    /**
     * Flush all of the model's records from the engine.
     */
    abstract public function flush(Model $model): void;

    /**
     * Create a search index.
     */
    abstract public function createIndex(string $name, array $options = []): mixed;

    /**
     * Delete a search index.
     */
    abstract public function deleteIndex(string $name): mixed;

    /**
     * Pluck and return the primary keys of the given results using the given key name.
     */
    public function mapIdsFrom(mixed $results, string $key): Collection
    {
        return $this->mapIds($results);
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     */
    public function keys(Builder $builder): Collection
    {
        return $this->mapIds($this->search($builder));
    }

    /**
     * Get the results of the given query mapped onto models.
     */
    public function get(Builder $builder): EloquentCollection
    {
        return $this->map(
            $builder,
            $builder->applyAfterRawSearchCallback($this->search($builder)),
            $builder->model
        );
    }

    /**
     * Get a lazy collection for the given query mapped onto models.
     */
    public function cursor(Builder $builder): LazyCollection
    {
        return $this->lazyMap(
            $builder,
            $builder->applyAfterRawSearchCallback($this->search($builder)),
            $builder->model
        );
    }
}
