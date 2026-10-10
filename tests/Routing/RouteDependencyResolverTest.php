<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Controller;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Route;
use TypeError;

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

    public function testTypedRouteParametersConvertWithPhpWeakTyping(): void
    {
        $container = new Container;
        $callables = new CallableDispatcher($container);
        $controllers = new ControllerDispatcher($container);
        $closure = static fn (int $id): int => $id;
        $route = (new Route('GET', '/posts/{id}', $closure))->bind(Request::create('/posts/123'));

        $this->assertSame(123, $callables->dispatch($route, $closure));
        $this->assertSame(123, $controllers->dispatch($route, new TypedRouteController, 'show'));
        $this->assertSame(123, $controllers->dispatch($route, new TypedRouteBaseController, 'show'));

        $invalid = (new Route('GET', '/posts/{id}', $closure))->bind(Request::create('/posts/abc'));

        $this->expectException(TypeError::class);

        $callables->dispatch($invalid, $closure);
    }
}

class TypedRouteController
{
    /**
     * Return the route identifier.
     */
    public function show(int $id): int
    {
        return $id;
    }
}

class TypedRouteBaseController extends Controller
{
    /**
     * Return the route identifier.
     */
    public function show(int $id): int
    {
        return $id;
    }
}

class InjectedRouteModel extends Model
{
}

class InjectedRouteService
{
    public int $hits = 0;
}
