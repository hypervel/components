<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engine;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

/**
 * No-op engine for disabling search functionality.
 *
 * Useful for testing or temporarily disabling search without code changes.
 */
class NullEngine extends Engine
{
    /**
     * Update the given models in the search index.
     */
    public function update(EloquentCollection $models): void
    {
        // No-op
    }

    /**
     * Remove the given models from the search index.
     */
    public function delete(EloquentCollection $models): void
    {
        // No-op
    }

    /**
     * Perform a search against the engine.
     */
    public function search(Builder $builder): mixed
    {
        return [];
    }

    /**
     * Perform a paginated search against the engine.
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return [];
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds(mixed $results): Collection
    {
        return new Collection();
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        return new EloquentCollection();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        return new LazyCollection();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     */
    public function getTotalCount(mixed $results): int
    {
        return is_countable($results) ? count($results) : 0;
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush(Model $model): void
    {
        // No-op
    }

    /**
     * Create a search index.
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        return [];
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex(string $name): mixed
    {
        return [];
    }
}
