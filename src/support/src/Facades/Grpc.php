<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Grpc\Server\GrpcRouteRegistrar;

/**
 * @method static \Hypervel\Routing\Route unary(string $method, array|string|callable $action)
 * @method static \Hypervel\Routing\Route serverStream(string $method, array|string|callable $action)
 * @method static void service(string $service, \Closure $routes)
 * @method static \Hypervel\Grpc\Server\PendingGrpcRegistration middleware(array|string $middleware)
 * @method static \Hypervel\Grpc\Server\PendingGrpcRegistration withoutMiddleware(array|string $middleware)
 * @method static \Hypervel\Grpc\Server\PendingGrpcRegistration name(string $name)
 *
 * @see \Hypervel\Grpc\Server\GrpcRouteRegistrar
 */
class Grpc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GrpcRouteRegistrar::class;
    }
}
