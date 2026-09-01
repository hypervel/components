<?php

declare(strict_types=1);

namespace Hypervel\Scout\Engines;

use Closure;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
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
     * The operation runner assigned to this engine.
     */
    protected ?EngineOperationRunner $operationRunner = null;

    /**
     * The configured name of this engine.
     */
    protected string $operationRunnerEngineName = '';

    /**
     * Update the given models in the search index.
     *
     * @param EloquentCollection<int, Model> $models
     */
    abstract public function update(EloquentCollection $models): void;

    /**
     * Remove the given models from the search index.
     *
     * @param EloquentCollection<int, Model> $models
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
     * Assign the operation runner to this engine.
     *
     * Boot-only. The runner persists on this engine for the worker lifetime.
     * Engines without observable operations retain no runner.
     *
     * @return $this
     */
    public function setOperationRunner(EngineOperationRunner $runner, string $engineName): static
    {
        if (! $this->hasObservableOperations()) {
            return $this;
        }

        $this->operationRunner = $runner;
        $this->operationRunnerEngineName = $engineName;

        return $this;
    }

    /**
     * Determine if this engine has operations worth observing.
     */
    protected function hasObservableOperations(): bool
    {
        return true;
    }

    /**
     * Update the given models through the operation runner.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function runUpdate(EloquentCollection $models): void
    {
        if ($models->isEmpty()
            || $this->operationRunner === null
            || ! $this->operationRunner->hasObservers()) {
            $this->update($models);

            return;
        }

        /** @var Model&SearchableInterface $model */
        $model = $models->first();

        $this->operationRunner->run(
            new EngineOperation(
                'update',
                $this->operationRunnerEngineName,
                $model::class,
                $model->indexableAs(),
                $models->count(),
            ),
            fn () => $this->update($models),
        );
    }

    /**
     * Delete the given models through the operation runner.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function runDelete(EloquentCollection $models): void
    {
        if ($models->isEmpty()
            || $this->operationRunner === null
            || ! $this->operationRunner->hasObservers()) {
            $this->delete($models);

            return;
        }

        /** @var Model&SearchableInterface $model */
        $model = $models->first();

        $this->operationRunner->run(
            new EngineOperation(
                'delete',
                $this->operationRunnerEngineName,
                $model::class,
                $model->indexableAs(),
                $models->count(),
            ),
            fn () => $this->delete($models),
        );
    }

    /**
     * Search through the operation runner.
     */
    public function runSearch(Builder $builder): mixed
    {
        if ($this->operationRunner === null || ! $this->operationRunner->hasObservers()) {
            return $this->search($builder);
        }

        return $this->operationRunner->run(
            new EngineOperation(
                'search',
                $this->operationRunnerEngineName,
                $builder->model::class,
                $builder->index ?? $builder->model->searchableAs(),
            ),
            fn () => $this->search($builder),
        );
    }

    /**
     * Paginate through the operation runner.
     */
    public function runPaginate(Builder $builder, int $perPage, int $page): mixed
    {
        if ($this->operationRunner === null || ! $this->operationRunner->hasObservers()) {
            return $this->paginate($builder, $perPage, $page);
        }

        return $this->operationRunner->run(
            new EngineOperation(
                'paginate',
                $this->operationRunnerEngineName,
                $builder->model::class,
                $builder->index ?? $builder->model->searchableAs(),
            ),
            fn () => $this->paginate($builder, $perPage, $page),
        );
    }

    /**
     * Flush the given model through the operation runner.
     */
    public function runFlush(Model $model): void
    {
        if ($this->operationRunner === null || ! $this->operationRunner->hasObservers()) {
            $this->flush($model);

            return;
        }

        /** @var Model&SearchableInterface $model */
        $this->operationRunner->run(
            new EngineOperation(
                'flush',
                $this->operationRunnerEngineName,
                $model::class,
                $model->indexableAs(),
            ),
            fn () => $this->flush($model),
        );
    }

    /**
     * Run a Builder operation through the operation runner.
     *
     * An omitted index uses the Builder's configured or searchable index. Pass
     * an explicit index when the operation targets another dataset.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @return TResult
     */
    public function runOperation(
        string $operation,
        Builder $builder,
        Closure $callback,
        ?string $index = null
    ): mixed {
        if ($this->operationRunner === null || ! $this->operationRunner->hasObservers()) {
            return $callback();
        }

        $index ??= $builder->index ?? $builder->model->searchableAs();

        return $this->operationRunner->run(
            new EngineOperation(
                $operation,
                $this->operationRunnerEngineName,
                $builder->model::class,
                $index,
            ),
            $callback,
        );
    }

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
        return $this->mapIds($this->runSearch($builder));
    }

    /**
     * Get the results of the given query mapped onto models.
     */
    public function get(Builder $builder): EloquentCollection
    {
        return $this->map(
            $builder,
            $builder->applyAfterRawSearchCallback($this->runSearch($builder)),
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
            $builder->applyAfterRawSearchCallback($this->runSearch($builder)),
            $builder->model
        );
    }
}
