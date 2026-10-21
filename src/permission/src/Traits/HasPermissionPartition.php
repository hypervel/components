<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Permission\Exceptions\PermissionPartitionViolation;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Scopes\PermissionPartitionScope;

trait HasPermissionPartition
{
    /**
     * Boot the permission partition global scope.
     */
    public static function bootHasPermissionPartition(): void
    {
        static::addGlobalScope(new PermissionPartitionScope);
    }

    /**
     * Populate the permission partition before model creation events run.
     */
    protected function performInsert(Builder $query): bool
    {
        $this->setPermissionPartitionForInsert();

        return parent::performInsert($query);
    }

    /**
     * Populate the permission partition before an insert that ignores unique conflicts.
     */
    protected function performInsertOrIgnore(Builder $query, array|string|null $uniqueBy): bool
    {
        $this->setPermissionPartitionForInsert();

        return parent::performInsertOrIgnore($query, $uniqueBy);
    }

    /**
     * Stamp the current partition before either model insertion path runs its listeners.
     */
    protected function setPermissionPartitionForInsert(): void
    {
        $partition = $this->permissionPartitionRegistrar()->resolvePartition();

        if ($partition) {
            if (array_key_exists($partition->column, $this->attributes)) {
                $this->permissionPartitionRegistrar()->ensureModelMatchesPartition($this, $partition);
            }

            $this->setAttribute($partition->column, $partition->value);
        }
    }

    /**
     * Get the authoritative attributes for a partitioned insert.
     */
    protected function getAttributesForInsert(): array
    {
        $partition = $this->permissionPartitionRegistrar()->resolvePartition();

        if ($partition) {
            $this->permissionPartitionRegistrar()->ensureModelMatchesPartition($this, $partition);

            // Creating listeners and mutators run after the initial assignment.
            $this->attributes[$partition->column] = $partition->value;
        }

        return parent::getAttributesForInsert();
    }

    /**
     * Add the permission partition to an existing model write query.
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query = parent::setKeysForSaveQuery($query);
        $partition = $this->permissionPartitionRegistrar()->resolvePartition();

        if (! $partition) {
            return $query;
        }

        $original = $this->permissionPartitionRegistrar()->partitionFromRecord($this)->value;

        if (! $partition->matches($original)) {
            throw PermissionPartitionViolation::forModel($this, $partition, $original);
        }

        $attributes = $this->getAttributes();

        if (! $partition->matches($attributes[$partition->column] ?? null)) {
            throw PermissionPartitionViolation::forImmutablePartition(
                $this,
                $partition,
                $original,
                $attributes[$partition->column] ?? null,
            );
        }

        return $query->where(
            $this->qualifyColumn($partition->column),
            $partition->value,
        );
    }

    /**
     * Add the permission partition to an existing model select query.
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query = parent::setKeysForSelectQuery($query);
        $partition = $this->permissionPartitionRegistrar()->resolvePartition();

        if (! $partition) {
            return $query;
        }

        $this->permissionPartitionRegistrar()->ensureModelMatchesPartition($this, $partition);

        return $query->where(
            $this->qualifyColumn($partition->column),
            $partition->value,
        );
    }

    /**
     * Create a partitioned query for queued model restoration.
     */
    public function newQueryForRestoration(array|int|string $ids): Builder
    {
        $query = parent::newQueryForRestoration($ids);
        $partition = $this->permissionPartitionRegistrar()->resolvePartition();

        return $partition
            ? $query->where($this->qualifyColumn($partition->column), $partition->value)
            : $query;
    }

    /**
     * Get the permission registrar used by the partition concern.
     */
    protected function permissionPartitionRegistrar(): PermissionRegistrar
    {
        return Container::getInstance()->make(PermissionRegistrar::class);
    }
}
