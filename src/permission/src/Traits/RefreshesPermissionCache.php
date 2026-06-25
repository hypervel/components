<?php

declare(strict_types=1);

namespace Hypervel\Permission\Traits;

use Hypervel\Container\Container;
use Hypervel\Permission\PermissionRegistrar;

trait RefreshesPermissionCache
{
    /**
     * Boot the permission cache refresh callbacks.
     */
    public static function bootRefreshesPermissionCache(): void
    {
        static::saved(function (): void {
            Container::getInstance()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        static::deleted(function (): void {
            Container::getInstance()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
