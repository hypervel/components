<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middleware;

use Closure;
use Hypervel\Contracts\Auth\Access\Authorizable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Http\Request;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Guard;
use Hypervel\Permission\Support\Config;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

use function Hypervel\Support\enum_value;

class RoleOrPermissionMiddleware
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
    public function handle(Request $request, Closure $next, array|string|UnitEnum $roleOrPermission, ?string $guard = null): Response
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

        if (! $user instanceof Authorizable || ! method_exists($user, 'hasAnyRole') || ! method_exists($user, 'hasAnyPermission')) {
            throw UnauthorizedException::missingTraitHasRoles($user);
        }

        $rolesOrPermissions = explode('|', self::parseRoleOrPermissionToString($roleOrPermission));
        $hasAnyRole = Closure::fromCallable([$user, 'hasAnyRole']);

        foreach ($rolesOrPermissions as $roleOrPermission) {
            if ($user->can($roleOrPermission)) {
                return $next($request);
            }
        }

        if ($hasAnyRole($rolesOrPermissions)) {
            return $next($request);
        }

        throw UnauthorizedException::forRolesOrPermissions($rolesOrPermissions);
    }

    /**
     * Specify the role or permission and guard for the middleware.
     */
    public static function using(array|string|UnitEnum $roleOrPermission, ?string $guard = null): string
    {
        $roleOrPermissionString = self::parseRoleOrPermissionToString($roleOrPermission);
        $args = is_null($guard) ? $roleOrPermissionString : "{$roleOrPermissionString},{$guard}";

        return static::class . ':' . $args;
    }

    /**
     * Parse the roles or permissions into a pipe-delimited string.
     */
    protected static function parseRoleOrPermissionToString(array|string|UnitEnum $roleOrPermission): string
    {
        $roleOrPermission = enum_value($roleOrPermission);

        if (is_array($roleOrPermission)) {
            return implode('|', array_map(fn ($r) => enum_value($r), $roleOrPermission));
        }

        return (string) $roleOrPermission;
    }
}
