<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Support\Collection as BaseCollection;
use RuntimeException;

/**
 * Provides full-text search capabilities to Eloquent models.
 *
 * @mixin \Hypervel\Database\Eloquent\Model
 */
trait Searchable
{
    /**
     * Additional metadata attributes managed by Scout.
     *
     * @var array<string, mixed>
     */
    protected array $scoutMetadata = [];

    /**
     * Concurrent runner for command batch operations.
     */
    protected static ?WaitConcurrent $scoutRunner = null;

    /**
     * Boot the searchable trait.
     */
    public static function bootSearchable(): void
    {
        static::addGlobalScope(new SearchableScope());

        (new static())->registerSearchableMacros();

        static::registerCallback('saved', function ($model): void {
            if (! static::isSearchSyncingEnabled()) {
                return;
            }

            if (! $model->searchIndexShouldBeUpdated()) {
                return;
            }

            if (! $model->shouldBeSearchable()) {
                if ($model->wasSearchableBeforeUpdate()) {
                    $model->unsearchable();
                }
                return;
            }

            $model->searchable();
        });

        static::registerCallback('deleted', function ($model): void {
            if (! static::isSearchSyncingEnabled()) {
                return;
            }

            if (! $model->wasSearchableBeforeDelete()) {
                return;
            }

            if (static::usesSoftDelete() && static::getScoutConfig('soft_delete', false)) {
                $model->searchable();
            } else {
                $model->unsearchable();
            }
        });

        static::registerCallback('forceDeleted', function ($model): void {
            if (! static::isSearchSyncingEnabled()) {
                return;
            }

            $model->unsearchable();
        });

        static::registerCallback('restored', function ($model): void {
            if (! static::isSearchSyncingEnabled()) {
                return;
            }

            // Note: restored is a "forced update" - we don't check searchIndexShouldBeUpdated()
            // because restored models should always be re-indexed

            if (! $model->shouldBeSearchable()) {
                if ($model->wasSearchableBeforeUpdate()) {
                    $model->unsearchable();
                }
                return;
            }

            $model->searchable();
        });
    }

    /**
     * Register the searchable macros on collections.
     */
    public function registerSearchableMacros(): void
    {
        BaseCollection::macro('searchable', function () {
            if ($this->isEmpty()) {
                return;
            }
            $this->first()->queueMakeSearchable($this);
        });

        BaseCollection::macro('unsearchable', function () {
            if ($this->isEmpty()) {
                return;
            }
            $this->first()->queueRemoveFromSearch($this);
        });

        BaseCollection::macro('searchableSync', function () {
            if ($this->isEmpty()) {
                return;
            }
            $this->first()->syncMakeSearchable($this);
        });

        BaseCollection::macro('unsearchableSync', function () {
            if ($this->isEmpty()) {
                return;
            }
            $this->first()->syncRemoveFromSearch($this);
        });
    }

    /**
     * Dispatch the job to make the given models searchable.
     */
    public function queueMakeSearchable(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        if (static::getScoutConfig('queue.enabled', false)) {
            $pendingDispatch = MakeSearchable::dispatch($models)
                ->onConnection($models->first()->syncWithSearchUsing())
                ->onQueue($models->first()->syncWithSearchUsingQueue());

            if (static::getScoutConfig('queue.after_commit', false)) {
                $pendingDispatch->afterCommit();
            }

            return;
        }

        static::dispatchSearchableJob(function () use ($models): void {
            $this->syncMakeSearchable($models);
        });
    }

    /**
     * Synchronously make the given models searchable.
     */
    public function syncMakeSearchable(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $models = $models->first()->makeSearchableUsing($models);

        if ($models->isEmpty()) {
            return;
        }

        $models->first()->searchableUsing()->update($models);
    }

    /**
     * Dispatch the job to make the given models unsearchable.
     */
    public function queueRemoveFromSearch(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        if (static::getScoutConfig('queue.enabled', false)) {
            $pendingDispatch = RemoveFromSearch::dispatch($models)
                ->onConnection($models->first()->syncWithSearchUsing())
                ->onQueue($models->first()->syncWithSearchUsingQueue());

            if (static::getScoutConfig('queue.after_commit', false)) {
                $pendingDispatch->afterCommit();
            }

            return;
        }

        static::dispatchSearchableJob(function () use ($models): void {
            $this->syncRemoveFromSearch($models);
        });
    }

    /**
     * Synchronously make the given models unsearchable.
     */
    public function syncRemoveFromSearch(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $models->first()->searchableUsing()->delete($models);
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    /**
     * When updating a model, this method determines if we should update the search index.
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        return true;
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @return Builder<static>
     */
    public static function search(string $query = '', ?Closure $callback = null): Builder
    {
        return new Builder(
            model: new static(),
            query: $query,
            callback: $callback,
            softDelete: static::usesSoftDelete() && static::getScoutConfig('soft_delete', false)
        );
    }

    /**
     * Make all instances of the model searchable.
     */
    public static function makeAllSearchable(?int $chunk = null): void
    {
        static::makeAllSearchableQuery()->searchable($chunk);
    }

    /**
     * Get a query builder for making all instances of the model searchable.
     */
    public static function makeAllSearchableQuery(): EloquentBuilder
    {
        $self = new static();
        $softDelete = static::usesSoftDelete() && static::getScoutConfig('soft_delete', false);

        return $self->newQuery()
            ->when(true, fn ($query) => $self->makeAllSearchableUsing($query))
            ->when($softDelete, fn ($query) => $query->withTrashed())
            ->orderBy($self->qualifyColumn($self->getScoutKeyName()));
    }

    /**
     * Modify the collection of models being made searchable.
     *
     * @param Collection<int, static> $models
     * @return Collection<int, static>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models;
    }

    /**
     * Modify the query used to retrieve models when making all of the models searchable.
     */
    protected function makeAllSearchableUsing(EloquentBuilder $query): EloquentBuilder
    {
        return $query;
    }

    /**
     * Make the given model instance searchable.
     */
    public function searchable(): void
    {
        $this->newCollection([$this])->searchable();
    }

    /**
     * Synchronously make the given model instance searchable.
     */
    public function searchableSync(): void
    {
        $this->newCollection([$this])->searchableSync();
    }

    /**
     * Remove all instances of the model from the search index.
     */
    public static function removeAllFromSearch(): void
    {
        $self = new static();
        $self->searchableUsing()->flush($self);
    }

    /**
     * Remove the given model instance from the search index.
     */
    public function unsearchable(): void
    {
        $this->newCollection([$this])->unsearchable();
    }

    /**
     * Synchronously remove the given model instance from the search index.
     */
    public function unsearchableSync(): void
    {
        $this->newCollection([$this])->unsearchableSync();
    }

    /**
     * Determine if the model existed in the search index prior to an update.
     */
    public function wasSearchableBeforeUpdate(): bool
    {
        return true;
    }

    /**
     * Determine if the model existed in the search index prior to deletion.
     */
    public function wasSearchableBeforeDelete(): bool
    {
        return true;
    }

    /**
     * Get the requested models from an array of object IDs.
     */
    public function getScoutModelsByIds(Builder $builder, array $ids): Collection
    {
        return $this->queryScoutModelsByIds($builder, $ids)->get();
    }

    /**
     * Get a query builder for retrieving the requested models from an array of object IDs.
     */
    public function queryScoutModelsByIds(Builder $builder, array $ids): EloquentBuilder
    {
        $query = static::usesSoftDelete()
            ? $this->withTrashed()
            : $this->newQuery();

        if ($builder->queryCallback) {
            call_user_func($builder->queryCallback, $query);
        }

        $whereIn = in_array($this->getScoutKeyType(), ['int', 'integer'])
            ? 'whereIntegerInRaw'
            : 'whereIn';

        return $query->{$whereIn}(
            $this->qualifyColumn($this->getScoutKeyName()),
            $ids
        );
    }

    /**
     * Enable search syncing for this model.
     */
    public static function enableSearchSyncing(): void
    {
        Context::set('__scout.syncing_disabled.' . static::class, false);
    }

    /**
     * Disable search syncing for this model.
     */
    public static function disableSearchSyncing(): void
    {
        Context::set('__scout.syncing_disabled.' . static::class, true);
    }

    /**
     * Determine if search syncing is enabled for this model.
     */
    public static function isSearchSyncingEnabled(): bool
    {
        return ! Context::get('__scout.syncing_disabled.' . static::class, false);
    }

    /**
     * Temporarily disable search syncing for the given callback.
     */
    public static function withoutSyncingToSearch(callable $callback): mixed
    {
        static::disableSearchSyncing();

        try {
            return $callback();
        } finally {
            static::enableSearchSyncing();
        }
    }

    /**
     * Get the index name for the model when searching.
     */
    public function searchableAs(): string
    {
        return static::getScoutConfig('prefix', '') . $this->getTable();
    }

    /**
     * Get the index name for the model when indexing.
     */
    public function indexableAs(): string
    {
        return $this->searchableAs();
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return $this->toArray();
    }

    /**
     * Get the Scout engine for the model.
     */
    public function searchableUsing(): Engine
    {
        return ApplicationContext::getContainer()->get(EngineManager::class)->engine();
    }

    /**
     * Get the queue connection that should be used when syncing.
     */
    public function syncWithSearchUsing(): ?string
    {
        return static::getScoutConfig('queue.connection');
    }

    /**
     * Get the queue that should be used with syncing.
     */
    public function syncWithSearchUsingQueue(): ?string
    {
        return static::getScoutConfig('queue.queue');
    }

    /**
     * Sync the soft deleted status for this model into the metadata.
     *
     * @return $this
     */
    public function pushSoftDeleteMetadata(): static
    {
        return $this->withScoutMetadata('__soft_deleted', $this->trashed() ? 1 : 0);
    }

    /**
     * Get all Scout related metadata.
     */
    public function scoutMetadata(): array
    {
        return $this->scoutMetadata;
    }

    /**
     * Set a Scout related metadata.
     *
     * @return $this
     */
    public function withScoutMetadata(string $key, mixed $value): static
    {
        $this->scoutMetadata[$key] = $value;

        return $this;
    }

    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed
    {
        return $this->getKey();
    }

    /**
     * Get the key name used to index the model.
     */
    public function getScoutKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Get the auto-incrementing key type for querying models.
     */
    public function getScoutKeyType(): string
    {
        return $this->getKeyType();
    }

    /**
     * Dispatch the job to scout the given models.
     */
    protected static function dispatchSearchableJob(callable $job): void
    {
        // Command path: use WaitConcurrent for parallel execution
        if (defined('SCOUT_COMMAND')) {
            if (! Coroutine::inCoroutine()) {
                throw new RuntimeException(
                    'Scout command must run within Hypervel\Coroutine\run(). '
                    . 'Wrap your command logic in run(function () { ... }).'
                );
            }

            if (! static::$scoutRunner instanceof WaitConcurrent) {
                static::$scoutRunner = new WaitConcurrent(
                    (int) static::getScoutConfig('concurrency', 50)
                );
            }
            static::$scoutRunner->create($job);
            return;
        }

        // HTTP/queue path: must be in coroutine
        if (! Coroutine::inCoroutine()) {
            throw new RuntimeException(
                'Scout searchable job must run in a coroutine context (HTTP request or queue job) '
                . 'or within a Scout command.'
            );
        }

        Coroutine::defer($job);
    }

    /**
     * Wait for all pending searchable jobs to complete.
     *
     * Should be called at the end of Scout commands to ensure all
     * concurrent indexing operations have finished.
     */
    public static function waitForSearchableJobs(): void
    {
        if (static::$scoutRunner instanceof WaitConcurrent) {
            static::$scoutRunner->wait();
            static::$scoutRunner = null;
        }
    }

    /**
     * Determine if the current class should use soft deletes with searching.
     */
    protected static function usesSoftDelete(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(static::class));
    }

    /**
     * Get a Scout configuration value.
     */
    protected static function getScoutConfig(string $key, mixed $default = null): mixed
    {
        return ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get("scout.{$key}", $default);
    }
}
