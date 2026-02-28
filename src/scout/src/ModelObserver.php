<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Support\Facades\Config;

/**
 * Observer that handles search index updates for Eloquent models.
 *
 * Listens to model events (saved, deleted, forceDeleted, restored) and
 * updates the search index accordingly. Supports soft deletes and
 * transaction-aware event dispatching.
 */
class ModelObserver
{
    /**
     * Context key prefix for storing whether syncing is disabled per model class.
     */
    protected const SYNCING_DISABLED_CONTEXT_KEY_PREFIX = '__scout.syncing_disabled.';

    /**
     * Indicates if Scout will dispatch the observer's events after all database transactions have committed.
     */
    public bool $afterCommit;

    /**
     * Indicates if Scout will keep soft deleted records in the search indexes.
     */
    protected bool $usingSoftDeletes;

    /**
     * Indicates if the model is currently force saving.
     */
    protected bool $forceSaving = false;

    /**
     * Create a new observer instance.
     */
    public function __construct()
    {
        $this->afterCommit = Config::boolean('scout.after_commit', false);
        $this->usingSoftDeletes = Config::boolean('scout.soft_delete', false);
    }

    /**
     * Enable syncing for the given class.
     *
     * Uses Context for coroutine-safe state management.
     *
     * @param class-string<Model> $class
     */
    public static function enableSyncingFor(string $class): void
    {
        Context::set(self::SYNCING_DISABLED_CONTEXT_KEY_PREFIX . $class, false);
    }

    /**
     * Disable syncing for the given class.
     *
     * Uses Context for coroutine-safe state management.
     *
     * @param class-string<Model> $class
     */
    public static function disableSyncingFor(string $class): void
    {
        Context::set(self::SYNCING_DISABLED_CONTEXT_KEY_PREFIX . $class, true);
    }

    /**
     * Determine if syncing is disabled for the given class or model.
     *
     * Uses Context for coroutine-safe state management.
     *
     * @param class-string<Model>|object $class
     */
    public static function syncingDisabledFor(object|string $class): bool
    {
        $class = is_object($class) ? get_class($class) : $class;

        return (bool) Context::get(self::SYNCING_DISABLED_CONTEXT_KEY_PREFIX . $class, false);
    }

    /**
     * Handle the saved event for the model.
     *
     * @param Model&SearchableInterface $model
     */
    public function saved(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        /* @phpstan-ignore method.notFound (provided by Searchable trait) */
        if (! $this->forceSaving && ! $model->searchIndexShouldBeUpdated()) {
            return;
        }

        if (! $model->shouldBeSearchable()) {
            /* @phpstan-ignore method.notFound (provided by Searchable trait) */
            if ($model->wasSearchableBeforeUpdate()) {
                $model->unsearchable();
            }

            return;
        }

        $model->searchable();
    }

    /**
     * Handle the deleted event for the model.
     *
     * @param Model&SearchableInterface $model
     */
    public function deleted(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        /* @phpstan-ignore method.notFound (provided by Searchable trait) */
        if (! $model->wasSearchableBeforeDelete()) {
            return;
        }

        if ($this->usingSoftDeletes && $this->usesSoftDelete($model)) {
            $this->whileForcingUpdate(function () use ($model): void {
                $this->saved($model);
            });
        } else {
            $model->unsearchable();
        }
    }

    /**
     * Handle the force deleted event for the model.
     *
     * @param Model&SearchableInterface $model
     */
    public function forceDeleted(Model $model): void
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        $model->unsearchable();
    }

    /**
     * Handle the restored event for the model.
     *
     * @param Model&SearchableInterface $model
     */
    public function restored(Model $model): void
    {
        $this->whileForcingUpdate(function () use ($model): void {
            $this->saved($model);
        });
    }

    /**
     * Execute the given callback while forcing updates.
     */
    protected function whileForcingUpdate(Closure $callback): mixed
    {
        $this->forceSaving = true;

        try {
            return $callback();
        } finally {
            $this->forceSaving = false;
        }
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
