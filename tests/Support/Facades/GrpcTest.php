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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class GrpcTest extends TestCase
{
    use VerifiesDoubles;

    public function testResolvesAndForwardsToThePackageRegistrar(): void
    {
        $container = new Container;
        $registrar = Double::for(GrpcRouteRegistrar::class)->strict();
        $route = Double::for(Route::class)->strict();
        $action = static fn (GPBEmpty $request): GPBEmpty => $request;
        $registrar->expects('unary')
            ->with('testing.Service/Call', $action)
            ->returns($route);
        $container->instance(GrpcRouteRegistrar::class, $registrar);
        Facade::setFacadeApplication($container);

        $this->assertSame($registrar, Grpc::getFacadeRoot());
        $this->assertSame($route, Grpc::unary('testing.Service/Call', $action));
    }

    public function testUsesTheOrdinaryFacadeResolutionCache(): void
    {
        $container = new Container;
        $first = Double::for(GrpcRouteRegistrar::class)->strict();
        $second = Double::for(GrpcRouteRegistrar::class)->strict();
        $container->instance(GrpcRouteRegistrar::class, $first);
        Facade::setFacadeApplication($container);

        $this->assertSame($first, Grpc::getFacadeRoot());
        $container->instance(GrpcRouteRegistrar::class, $second);
        $this->assertSame($first, Grpc::getFacadeRoot());

        Grpc::clearResolvedInstance();

        $this->assertSame($second, Grpc::getFacadeRoot());
    }
}
