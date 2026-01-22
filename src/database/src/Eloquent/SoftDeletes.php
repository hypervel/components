<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Events\QueuedClosure;
use Hypervel\Support\Collection as BaseCollection;

/**
 * @method static \Hypervel\Database\Eloquent\Builder<static> withTrashed(bool $withTrashed = true)
 * @method static \Hypervel\Database\Eloquent\Builder<static> onlyTrashed()
 * @method static \Hypervel\Database\Eloquent\Builder<static> withoutTrashed()
 * @method static static restoreOrCreate(array<string, mixed> $attributes = [], array<string, mixed> $values = [])
 * @method static static createOrRestore(array<string, mixed> $attributes = [], array<string, mixed> $values = [])
 */
trait SoftDeletes
{
    /**
     * Indicates if the model is currently force deleting.
     */
    protected bool $forceDeleting = false;

    /**
     * Boot the soft deleting trait for a model.
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new SoftDeletingScope);
    }

    /**
     * Initialize the soft deleting trait for an instance.
     */
    public function initializeSoftDeletes(): void
    {
        if (! isset($this->casts[$this->getDeletedAtColumn()])) {
            $this->casts[$this->getDeletedAtColumn()] = 'datetime';
        }
    }

    /**
     * Force a hard delete on a soft deleted model.
     */
    public function forceDelete(): ?bool
    {
        if ($this->fireModelEvent('forceDeleting') === false) {
            return false;
        }

        $this->forceDeleting = true;

        return tap($this->delete(), function ($deleted) {
            $this->forceDeleting = false;

            if ($deleted) {
                $this->fireModelEvent('forceDeleted', false);
            }
        });
    }

    /**
     * Force a hard delete on a soft deleted model without raising any events.
     */
    public function forceDeleteQuietly(): ?bool
    {
        return static::withoutEvents(fn () => $this->forceDelete());
    }

    /**
     * Destroy the models for the given IDs.
     */
    public static function forceDestroy(Collection|BaseCollection|array|int|string $ids): int
    {
        if ($ids instanceof Collection) {
            $ids = $ids->modelKeys();
        }

        if ($ids instanceof BaseCollection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        if (count($ids) === 0) {
            return 0;
        }

        // We will actually pull the models from the database table and call delete on
        // each of them individually so that their events get fired properly with a
        // correct set of attributes in case the developers wants to check these.
        $key = ($instance = new static)->getKeyName();

        $count = 0;

        foreach ($instance->withTrashed()->whereIn($key, $ids)->get() as $model) {
            if ($model->forceDelete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Perform the actual delete query on this model instance.
     */
    protected function performDeleteOnModel(): mixed
    {
        if ($this->forceDeleting) {
            return tap($this->setKeysForSaveQuery($this->newModelQuery())->forceDelete(), function () {
                $this->exists = false;
            });
        }

        return $this->runSoftDelete();
    }

    /**
     * Perform the actual delete query on this model instance.
     */
    protected function runSoftDelete(): void
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }

    /**
     * Restore a soft-deleted model instance.
     */
    public function restore(): bool
    {
        // If the restoring event does not return false, we will proceed with this
        // restore operation. Otherwise, we bail out so the developer will stop
        // the restore totally. We will clear the deleted timestamp and save.
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $this->{$this->getDeletedAtColumn()} = null;

        // Once we have saved the model, we will fire the "restored" event so this
        // developer will do anything they need to after a restore operation is
        // totally finished. Then we will return the result of the save call.
        $this->exists = true;

        $result = $this->save();

        $this->fireModelEvent('restored', false);

        return $result;
    }

    /**
     * Restore a soft-deleted model instance without raising any events.
     */
    public function restoreQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->restore());
    }

    /**
     * Determine if the model instance has been soft-deleted.
     */
    public function trashed(): bool
    {
        return ! is_null($this->{$this->getDeletedAtColumn()});
    }

    /**
     * Register a "softDeleted" model event callback with the dispatcher.
     */
    public static function softDeleted(QueuedClosure|callable|string $callback): void
    {
        static::registerModelEvent('trashed', $callback);
    }

    /**
     * Register a "restoring" model event callback with the dispatcher.
     */
    public static function restoring(QueuedClosure|callable|string $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a "restored" model event callback with the dispatcher.
     */
    public static function restored(QueuedClosure|callable|string $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Register a "forceDeleting" model event callback with the dispatcher.
     */
    public static function forceDeleting(QueuedClosure|callable|string $callback): void
    {
        static::registerModelEvent('forceDeleting', $callback);
    }

    /**
     * Register a "forceDeleted" model event callback with the dispatcher.
     */
    public static function forceDeleted(QueuedClosure|callable|string $callback): void
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    /**
     * Determine if the model is currently force deleting.
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Get the name of the "deleted at" column.
     */
    public function getDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    /**
     * Get the fully qualified "deleted at" column.
     */
    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->qualifyColumn($this->getDeletedAtColumn());
    }
}
