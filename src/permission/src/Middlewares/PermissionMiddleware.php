<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middlewares;

use BackedEnum;
use Hyperf\Contract\ContainerInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Permission\Exceptions\PermissionException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnitEnum;

class PermissionMiddleware implements MiddlewareInterface
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(protected ContainerInterface $container)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string ...$permissions
    ): ResponseInterface {
        $auth = $this->container->get(AuthManager::class);
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
                0,
                null,
                [],
                $permissions
            );
        }

        return $handler->handle($request);
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
