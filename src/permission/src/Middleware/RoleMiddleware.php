<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middleware;

use BackedEnum;
use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Permission\Exceptions\RoleException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

class RoleMiddleware
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
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $auth = $this->container->make(AuthManager::class);
        $user = $auth->user();
        if (! $user) {
            throw new UnauthorizedException(
                401,
                sprintf(
                    'User is not authenticated. Cannot check roles: %s',
                    self::parseRolesToString($roles)
                )
            );
        }

        if (! method_exists($user, 'hasAnyRoles')) {
            throw new UnauthorizedException(
                500,
                sprintf(
                    'User "%s" does not have the "hasAnyRoles" method. Cannot check roles: %s',
                    /* @phpstan-ignore-next-line */
                    $user->getAuthIdentifier(),
                    self::parseRolesToString($roles)
                )
            );
        }
        $roles = explode('|', self::parseRolesToString($roles));
        /* @phpstan-ignore-next-line */
        if (! $user->hasAnyRoles($roles)) {
            throw new RoleException(
                403,
                sprintf(
                    'User "%s" does not have any of the required roles: %s',
                    /* @phpstan-ignore-next-line */
                    $user->getAuthIdentifier(),
                    self::parseRolesToString($roles)
                ),
                null,
                [],
                0,
                $roles
            );
        }

        return $next($request);
    }

    /**
     * Generate a unique identifier for the middleware based on the roles.
     */
    public static function using(array|UnitEnum|int|string ...$roles): string
    {
        return static::class . ':' . self::parseRolesToString($roles);
    }

    public static function parseRolesToString(array $roles)
    {
        $roles = Collection::make($roles)
            ->flatten()
            ->values()
            ->all();

        $role = array_map(function ($role) {
            return match (true) {
                $role instanceof BackedEnum => $role->value,
                $role instanceof UnitEnum => $role->name,
                default => $role,
            };
        }, $roles);

        return implode('|', $role);
    }
}
