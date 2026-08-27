<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionPartition;

trait RefreshesPermissionCache
{
    /**
     * Boot the permission cache refresh callbacks.
     */
    public static function bootRefreshesPermissionCache(): void
    {
        static::saving(function (Model $model): void {
            Container::getInstance()
                ->make(PermissionRegistrar::class)
                ->ensureModelUsesPermissionConnection($model);
        });

        static::deleting(function (Model $model): void {
            $registrar = Container::getInstance()->make(PermissionRegistrar::class);
            $registrar->ensureModelUsesPermissionConnection($model);
            $partition = static::permissionPartitionForLifecycle($model, $registrar);

            // Catalog invalidation includes transactional soft deletes; assignment rotation is hard-delete only.
            $registrar->invalidatePermissionCatalogAfterMutation($partition);

            if (static::shouldDeletePermissionAssignments($model)) {
                $registrar->rotateModelAssignmentCacheTokenAfterMutation($partition);
            }
        });

        static::saved(function (Model $model): void {
            $registrar = Container::getInstance()->make(PermissionRegistrar::class);

            $registrar->invalidatePermissionCatalogAfterMutation(
                static::permissionPartitionForLifecycle($model, $registrar),
            );
        });

        static::deleted(function (Model $model): void {
            $registrar = Container::getInstance()->make(PermissionRegistrar::class);

            $registrar->invalidatePermissionCatalogAfterMutation(
                static::permissionPartitionForLifecycle($model, $registrar),
            );
        });
    }

    /**
     * Resolve the persisted partition used for lifecycle cleanup and invalidation.
     */
    protected static function permissionPartitionForLifecycle(
        Model $model,
        PermissionRegistrar $registrar,
    ): ?PermissionPartition {
        return PermissionRegistrar::partitioningEnabled()
            ? $registrar->partitionFromRecord($model)
            : null;
    }
}
