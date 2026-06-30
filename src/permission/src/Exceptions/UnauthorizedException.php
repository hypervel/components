<?php

declare(strict_types=1);

namespace Hypervel\Permission\Exceptions;

use Hypervel\Contracts\Auth\Access\Authorizable;
use Hypervel\Permission\Support\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    /**
     * The roles required by the failed check.
     *
     * @var array<array-key, string>
     */
    private array $requiredRoles = [];

    /**
     * The permissions required by the failed check.
     *
     * @var array<array-key, string>
     */
    private array $requiredPermissions = [];

    /**
     * Create an exception for missing roles.
     *
     * @param array<array-key, string> $roles
     */
    public static function forRoles(array $roles): static
    {
        $message = __('User does not have the right roles.');

        if (Config::displayRoleInException()) {
            $message .= ' ' . __('Necessary roles are :roles', ['roles' => implode(', ', $roles)]);
        }

        $exception = new static(403, $message, null, []);
        $exception->requiredRoles = $roles;

        return $exception;
    }

    /**
     * Create an exception for missing permissions.
     *
     * @param array<array-key, string> $permissions
     */
    public static function forPermissions(array $permissions): static
    {
        $message = __('User does not have the right permissions.');

        if (Config::displayPermissionInException()) {
            $message .= ' ' . __('Necessary permissions are :permissions', ['permissions' => implode(', ', $permissions)]);
        }

        $exception = new static(403, $message, null, []);
        $exception->requiredPermissions = $permissions;

        return $exception;
    }

    /**
     * Create an exception for missing roles or permissions.
     *
     * @param array<array-key, string> $rolesOrPermissions
     */
    public static function forRolesOrPermissions(array $rolesOrPermissions): static
    {
        $message = __('User does not have any of the necessary access rights.');

        if (Config::displayPermissionInException() && Config::displayRoleInException()) {
            $message .= ' ' . __('Necessary roles or permissions are :values', ['values' => implode(', ', $rolesOrPermissions)]);
        }

        $exception = new static(403, $message, null, []);
        $exception->requiredPermissions = $rolesOrPermissions;

        return $exception;
    }

    /**
     * Create an exception for a user missing the HasRoles trait.
     */
    public static function missingTraitHasRoles(Authorizable $user): static
    {
        return new static(403, __('Authorizable class `:class` must use Hypervel\Permission\Traits\HasRoles trait.', [
            'class' => $user::class,
        ]), null, []);
    }

    /**
     * Create an exception for a missing authenticated user.
     */
    public static function notLoggedIn(): static
    {
        return new static(403, __('User is not logged in.'), null, []);
    }

    /**
     * Get the roles required by the failed check.
     *
     * @return array<array-key, string>
     */
    public function getRequiredRoles(): array
    {
        return $this->requiredRoles;
    }

    /**
     * Get the permissions required by the failed check.
     *
     * @return array<array-key, string>
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }
}
