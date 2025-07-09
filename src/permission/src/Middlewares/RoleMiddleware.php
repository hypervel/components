<?php

declare(strict_types=1);

namespace Hypervel\Permission\Middlewares;

use BackedEnum;
use Hypervel\Permission\Exceptions\RoleException;
use Hypervel\Permission\Exceptions\UnauthorizedException;
use Hypervel\Permission\Traits\HasRole;
use Hypervel\Support\Facades\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnitEnum;

class RoleMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        BackedEnum|int|string|UnitEnum ...$roles
    ): ResponseInterface {
        /** @var HasRole $user */
        $user = Auth::user();
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
                    $user->getAuthIdentifier(),
                    self::parseRolesToString($roles)
                )
            );
        }
        $roleString = self::parseRolesToString($roles);
        if (! $user->hasAnyRoles($roles)) {
            throw new RoleException(
                403,
                sprintf(
                    'User "%s" does not have any of the required roles: %s',
                    $user->getAuthIdentifier(),
                    self::parseRolesToString($roles)
                ),
                0,
                null,
                [],
                explode(',', $roleString)
            );
        }

        return $handler->handle($request);
    }

    /**
     * Generate a unique identifier for the middleware based on the roles.
     */
    public static function using(array|BackedEnum|int|string|UnitEnum ...$roles): string
    {
        return static::class . ':' . self::parseRolesToString($roles);
    }

    public static function parseRolesToString(array $roles)
    {
        $roles = collect($roles)
            ->flatten()
            ->values()
            ->all();
        if ($roles instanceof BackedEnum) {
            $roles = $roles->value;
        }

        $role = array_map(function ($role) {
            return match (true) {
                $role instanceof BackedEnum => $role->value,
                $role instanceof UnitEnum => $role->name,
                default => $role,
            };
        }, $roles);

        return implode(',', $role);
    }
}
