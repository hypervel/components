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

class RouteMiddlewareCachingTest extends RoutingTestCase
{
    public function testResolvedMiddlewareIsCachedOnRoute(): void
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

    public function testResolvedMiddlewareIsNullBeforeGathering(): void
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $this->assertNull($route->resolvedMiddleware);
    }

    public function testFlushControllerClearsResolvedMiddleware(): void
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

    public function testPrepareForSerializationClearsResolvedMiddleware(): void
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

    public function testControllerRouteMiddlewareIsCached(): void
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

    public function testSettingTheSameContainerPreservesResolvedMiddleware(): void
    {
        $container = new Container;
        $dispatcherResolutions = 0;
        $router = $this->getRouter($container);

        $container->bind(ControllerDispatcherContract::class, function ($app) use (&$dispatcherResolutions) {
            ++$dispatcherResolutions;

            return new ControllerDispatcher($app);
        });

        $route = $router->get('foo', MiddlewareController::class . '@index');

        $router->gatherRouteMiddleware($route);
        $resolvedMiddleware = $route->resolvedMiddleware;

        $route->setContainer($container);

        $this->assertSame($resolvedMiddleware, $route->resolvedMiddleware);
        $this->assertSame($resolvedMiddleware, $router->gatherRouteMiddleware($route));
        $this->assertSame(1, $dispatcherResolutions);
    }

    public function testSettingADifferentContainerClearsResolvedMiddleware(): void
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $router->gatherRouteMiddleware($route);
        $this->assertNotNull($route->computedMiddleware);
        $this->assertNotNull($route->resolvedMiddleware);

        $route->setContainer(new Container);

        $this->assertNull($route->computedMiddleware);
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
    public function handle(mixed $request, Closure $next): mixed
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
