<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Facades;

use Google\Protobuf\GPBEmpty;
use Hypervel\Container\Container;
use Hypervel\Grpc\Server\GrpcRouteRegistrar;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Grpc;
use Hypervel\Tests\TestCase;
use Mockery as m;

class GrpcTest extends TestCase
{
    public function testResolvesAndForwardsToThePackageRegistrar(): void
    {
        $container = new Container;
        $registrar = m::mock(GrpcRouteRegistrar::class);
        $route = m::mock(Route::class);
        $action = static fn (GPBEmpty $request): GPBEmpty => $request;
        $registrar->shouldReceive('unary')
            ->once()
            ->with('testing.Service/Call', $action)
            ->andReturn($route);
        $container->instance(GrpcRouteRegistrar::class, $registrar);
        Facade::setFacadeApplication($container);

        $this->assertSame($registrar, Grpc::getFacadeRoot());
        $this->assertSame($route, Grpc::unary('testing.Service/Call', $action));
    }

    public function testUsesTheOrdinaryFacadeResolutionCache(): void
    {
        $container = new Container;
        $first = m::mock(GrpcRouteRegistrar::class);
        $second = m::mock(GrpcRouteRegistrar::class);
        $container->instance(GrpcRouteRegistrar::class, $first);
        Facade::setFacadeApplication($container);

        $this->assertSame($first, Grpc::getFacadeRoot());
        $container->instance(GrpcRouteRegistrar::class, $second);
        $this->assertSame($first, Grpc::getFacadeRoot());

        Grpc::clearResolvedInstance();

        $this->assertSame($second, Grpc::getFacadeRoot());
    }
}
