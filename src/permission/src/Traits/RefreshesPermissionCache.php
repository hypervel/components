<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Permission\PermissionRegistrar;

trait RefreshesPermissionCache
{
    /**
     * Boot the permission cache refresh callbacks.
     */
    public static function bootRefreshesPermissionCache(): void
    {
        static::saved(function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        static::deleted(function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
