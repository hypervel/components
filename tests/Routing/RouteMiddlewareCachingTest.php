<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteMiddlewareCachingTest;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Routing\Controller;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Router;
use Hypervel\Tests\Routing\RoutingTestCase;

/**
 * @internal
 * @coversNothing
 */
class RouteMiddlewareCachingTest extends RoutingTestCase
{
    public function testResolvedMiddlewareIsCachedOnRoute()
    {
        $router = $this->getRouter();
        $router->aliasMiddleware('testmw', TestMiddleware::class);

        $route = $router->get('foo', ['middleware' => 'testmw', 'uses' => function () {
            return 'ok';
        }]);

        $first = $router->gatherRouteMiddleware($route);
        $second = $router->gatherRouteMiddleware($route);

        $this->assertSame($first, $second);
        $this->assertSame([TestMiddleware::class], $first);
        $this->assertNotNull($route->resolvedMiddleware);
    }

    public function testResolvedMiddlewareIsNullBeforeGathering()
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $this->assertNull($route->resolvedMiddleware);
    }

    public function testFlushControllerClearsResolvedMiddleware()
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $router->gatherRouteMiddleware($route);
        $this->assertNotNull($route->resolvedMiddleware);

        $route->flushController();

        $this->assertNull($route->resolvedMiddleware);
    }

    public function testPrepareForSerializationClearsResolvedMiddleware()
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $router->gatherRouteMiddleware($route);
        $this->assertNotNull($route->resolvedMiddleware);

        $route->prepareForSerialization();

        $this->assertNull($route->resolvedMiddleware);
    }

    public function testControllerRouteMiddlewareIsCached()
    {
        $router = $this->getRouter();
        $route = $router->get('foo', MiddlewareController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        // Middleware has been gathered during dispatch
        $route = $request->route();
        $this->assertNotNull($route->resolvedMiddleware);

        // Second call returns the cached result
        $first = $router->gatherRouteMiddleware($route);
        $second = $router->gatherRouteMiddleware($route);
        $this->assertSame($first, $second);
    }

    public function testSetContainerClearsResolvedMiddleware()
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $router->gatherRouteMiddleware($route);
        $this->assertNotNull($route->resolvedMiddleware);

        $route->setContainer(new Container);

        $this->assertNull($route->resolvedMiddleware);
    }

    protected function getRouter(?Container $container = null): Router
    {
        $container ??= new Container;

        $router = new Router($container->make(Dispatcher::class), $container);

        $container->instance(Registrar::class, $router);

        $container->bind(ControllerDispatcherContract::class, fn ($app) => new ControllerDispatcher($app));
        $container->bind(CallableDispatcherContract::class, fn ($app) => new CallableDispatcher($app));

        return $router;
    }
}

class TestMiddleware
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}

class MiddlewareController extends Controller
{
    public function __construct()
    {
        $this->middleware(TestMiddleware::class);
    }

    public function index(): string
    {
        return 'ok';
    }
}
