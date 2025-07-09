<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middlewares;

use BackedEnum;
use Hypervel\Permission\Exceptions\PermissionException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Support\Facades\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnitEnum;

class PermissionMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        BackedEnum|int|string|UnitEnum ...$permissions
    ): ResponseInterface {
        $user = Auth::user();
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
        $permissionString = self::parsePermissionsToString($permissions);
        /* @phpstan-ignore-next-line */
        if (! $user->hasAnyPermissions($permissions)) {
            throw new PermissionException(
                403,
                sprintf(
                    'User "%s" does not have any of the required permissions: %s',
                    $user->getAuthIdentifier(),
                    self::parsePermissionsToString($permissions)
                ),
                0,
                null,
                [],
                explode(',', $permissionString)
            );
        }

        return $handler->handle($request);
    }

    /**
     * Generate a unique identifier for the middleware based on the permissions.
     */
    public static function using(array|BackedEnum|int|string|UnitEnum ...$permissions): string
    {
        return static::class . ':' . self::parsePermissionsToString($permissions);
    }

    public static function parsePermissionsToString(array $permissions)
    {
        $permissions = collect($permissions)
            ->flatten()
            ->values()
            ->all();
        if ($permissions instanceof BackedEnum) {
            $permissions = $permissions->value;
        }

        $permission = array_map(function ($permission) {
            return match (true) {
                $permission instanceof BackedEnum => $permission->value,
                $permission instanceof UnitEnum => $permission->name,
                default => $permission,
            };
        }, $permissions);

        return implode(',', $permission);
    }
}
