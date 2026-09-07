<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Route;

class RouteDependencyResolverTest extends RoutingTestCase
{
    public function testInjectedModelsAreFreshWhileOrdinaryServicesRemainShared(): void
    {
        $container = new Container;
        $dispatcher = new CallableDispatcher($container);
        $action = function (InjectedRouteModel $model, InjectedRouteService $service): array {
            $modelWasDirty = $model->exists || $model->getAttribute('leaked') !== null;
            $model->exists = true;
            $model->setAttribute('leaked', true);

            return [$modelWasDirty, ++$service->hits];
        };
        $route = new Route('GET', '/injected-model', $action);
        $route->bind(Request::create('/injected-model'));

        $this->assertSame([false, 1], $dispatcher->dispatch($route, $action));
        $this->assertSame([false, 2], $dispatcher->dispatch($route, $action));
    }
}

class InjectedRouteModel extends Model
{
}

class InjectedRouteService
{
    public int $hits = 0;
}
