<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middleware;

use BackedEnum;
use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Permission\Exceptions\PermissionException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

class PermissionMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $auth = $this->container->make('auth');
        $user = $auth->user();
        if (! $user) {
            throw new UnauthorizedException(
                401,
                sprintf(
                    'User is not authenticated. Cannot check permissions: %s',
                    self::parsePermissionsToString($permissions)
                )
            );
        }

        if (! method_exists($user, 'hasAnyPermissions')) {
            throw new UnauthorizedException(
                500,
                sprintf(
                    'User "%s" does not have the "hasAnyPermissions" method. Cannot check permissions: %s',
                    /* @phpstan-ignore-next-line */
                    $user->getAuthIdentifier(),
                    self::parsePermissionsToString($permissions)
                )
            );
        }
        $permissions = explode('|', self::parsePermissionsToString($permissions));
        /* @phpstan-ignore-next-line */
        if (! $user->hasAnyPermissions($permissions)) {
            throw new PermissionException(
                403,
                sprintf(
                    'User "%s" does not have any of the required permissions: %s',
                    /* @phpstan-ignore-next-line */
                    $user->getAuthIdentifier(),
                    self::parsePermissionsToString($permissions)
                ),
                null,
                [],
                0,
                $permissions
            );
        }

        return $next($request);
    }

    /**
     * Generate a unique identifier for the middleware based on the permissions.
     */
    public static function using(array|UnitEnum|int|string ...$permissions): string
    {
        return static::class . ':' . self::parsePermissionsToString($permissions);
    }

    public static function parsePermissionsToString(array $permissions)
    {
        $permissions = Collection::make($permissions)
            ->flatten()
            ->values()
            ->all();

        $permission = array_map(function ($permission) {
            return match (true) {
                $permission instanceof BackedEnum => $permission->value,
                $permission instanceof UnitEnum => $permission->name,
                default => $permission,
            };
        }, $permissions);

        return implode('|', $permission);
    }
}
