<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;

if (! function_exists('getModelForGuard')) {
    /**
     * Get the model class for a guard.
     *
     * @return null|class-string<Model>
     */
    function getModelForGuard(string $guard): ?string
    {
        return Guard::getModelForGuard($guard);
    }
}

if (! function_exists('setPermissionsTeamId')) {
    /**
     * Set the current permissions team id.
     */
    function setPermissionsTeamId(int|string|Model|null $id): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($id);
    }
}

if (! function_exists('getPermissionsTeamId')) {
    /**
     * Get the current permissions team id.
     */
    function getPermissionsTeamId(): int|string|null
    {
        return app(PermissionRegistrar::class)->getPermissionsTeamId();
    }
}
