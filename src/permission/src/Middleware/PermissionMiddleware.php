<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middleware;

use Closure;
use Hypervel\Contracts\Auth\Access\Authorizable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Guard;
use Hypervel\Permission\Support\Config;
use UnitEnum;

use function Hypervel\Support\enum_value;

class PermissionMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(protected AuthFactory $auth)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, mixed $permission, ?string $guard = null): Response
    {
        $guard = Guard::normalizeName($guard);
        $authGuard = $this->auth->guard($guard);

        $user = $authGuard->user();

        // For machine-to-machine Passport clients
        if (! $user && $request->bearerToken() && Config::usePassportClientCredentials()) {
            $user = Guard::getPassportClient($guard);
        }

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! $user instanceof Authorizable || ! method_exists($user, 'hasAnyPermission')) {
            throw UnauthorizedException::missingTraitHasRoles($user);
        }

        $permissions = explode('|', self::parsePermissionsToString($permission));

        foreach ($permissions as $permissionName) {
            if ($user->can($permissionName)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forPermissions($permissions);
    }

    /**
     * Specify the permission and guard for the middleware.
     */
    public static function using(array|string|UnitEnum $permission, ?string $guard = null): string
    {
        $permissionString = self::parsePermissionsToString($permission);

        $args = is_null($guard) ? $permissionString : "{$permissionString},{$guard}";

        return static::class . ':' . $args;
    }

    /**
     * Parse the permissions into a pipe-delimited string.
     */
    protected static function parsePermissionsToString(array|string|UnitEnum $permission): string
    {
        $permission = enum_value($permission);

        if (is_array($permission)) {
            return implode('|', array_map(
                fn ($name) => $name instanceof UnitEnum ? (string) enum_value($name) : $name,
                $permission
            ));
        }

        return (string) $permission;
    }
}
