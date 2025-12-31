<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Support\Collection as BaseCollection;


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
     * Concurrent runner for batch operations.
     */
    protected static ?Concurrent $scoutRunner = null;

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

            if (! $model->shouldBeSearchable()) {
                $model->unsearchable();
                return;
            }

            $model->searchable();
        });

        static::registerCallback('deleted', function ($model): void {
            if (! static::isSearchSyncingEnabled()) {
                return;
            }

            if (static::usesSoftDelete() && static::getScoutConfig('soft_delete', false)) {
                $model->searchable();
            } else {
                $model->unsearchable();
            }
        });
    }

    /**
     * Register the searchable macros on collections.
     */
    public function registerSearchableMacros(): void
    {
        $self = $this;

        BaseCollection::macro('searchable', function (?int $chunk = null) use ($self) {
            $self->queueMakeSearchable($this);
        });

        BaseCollection::macro('unsearchable', function () use ($self) {
            $self->queueRemoveFromSearch($this);
        });

        BaseCollection::macro('searchableSync', function () use ($self) {
            $self->syncMakeSearchable($this);
        });

        BaseCollection::macro('unsearchableSync', function () use ($self) {
            $self->syncRemoveFromSearch($this);
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
            // Queue-based indexing will be implemented with Jobs
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

        $models->first()->makeSearchableUsing($models)->first()->searchableUsing()->update($models);
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
            // Queue-based removal will be implemented with Jobs
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
     * @return BaseCollection<int, static>
     */
    public function makeSearchableUsing(BaseCollection $models): BaseCollection
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
     * Get the concurrency that should be used when syncing.
     */
    public function syncWithSearchUsingConcurrency(): int
    {
        return (int) static::getScoutConfig('concurrency', 100);
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
        if (! Coroutine::inCoroutine()) {
            $job();
            return;
        }

        if (defined('SCOUT_COMMAND')) {
            if (! static::$scoutRunner instanceof Concurrent) {
                static::$scoutRunner = new Concurrent((new static())->syncWithSearchUsingConcurrency());
            }
            static::$scoutRunner->create($job);
        } else {
            Coroutine::defer($job);
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
