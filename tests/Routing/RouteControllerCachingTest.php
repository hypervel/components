<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteControllerCachingTest;

use Hypervel\Container\Attributes\Scoped;
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
use ReflectionProperty;

class RouteControllerCachingTest extends RoutingTestCase
{
    public function testUnboundControllerIsCachedOnRouteProperty(): void
    {
        $router = $this->getRouter();
        $router->get('foo', UnboundController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $first = $request->route()->getController();
        $second = $request->route()->getController();

        $this->assertSame($first, $second);
    }

    public function testScopedControllerUsesContextNotRouteProperty(): void
    {
        $router = $this->getRouter();
        $router->get('foo', ScopedController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $route = $request->route();
        $controller = $route->getController();

        $this->assertInstanceOf(ScopedController::class, $controller);

        // The controller property on the route should be null — scoped
        // controllers are stored in Context, not on the Route instance.
        $reflection = new ReflectionProperty($route, 'controller');
        $this->assertNull($reflection->getValue($route));
    }

    public function testBoundControllerUsesContextNotRouteProperty(): void
    {
        $container = new Container;
        $container->bind(BoundController::class);

        $router = $this->getRouter($container);
        $router->get('foo', BoundController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $route = $request->route();
        $controller = $route->getController();

        $this->assertInstanceOf(BoundController::class, $controller);

        $reflection = new ReflectionProperty($route, 'controller');
        $this->assertNull($reflection->getValue($route));
    }

    public function testSingletonControllerIsCachedOnRouteProperty(): void
    {
        $container = new Container;
        $container->singleton(SingletonController::class);

        $router = $this->getRouter($container);
        $router->get('foo', SingletonController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $route = $request->route();
        $first = $route->getController();
        $second = $route->getController();

        $this->assertSame($first, $second);

        $reflection = new ReflectionProperty($route, 'controller');
        $this->assertNotNull($reflection->getValue($route));
    }

    public function testFlushControllerClearsPropertyCache(): void
    {
        $router = $this->getRouter();
        $router->get('foo', UnboundController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $route = $request->route();
        $route->getController();

        $reflection = new ReflectionProperty($route, 'controller');
        $this->assertNotNull($reflection->getValue($route));

        $route->flushController();

        $this->assertNull($reflection->getValue($route));
    }

    public function testSetContainerClearsControllerCache(): void
    {
        $container = new Container;
        $router = $this->getRouter($container);
        $router->get('foo', UnboundController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $route = $request->route();
        $route->getController();

        $controllerProperty = new ReflectionProperty($route, 'controller');
        $cacheDecisionProperty = new ReflectionProperty($route, 'shouldCacheControllerOnRoute');

        $this->assertNotNull($controllerProperty->getValue($route));
        $this->assertNotNull($cacheDecisionProperty->getValue($route));

        // Swapping the container should clear all controller caches
        // since the caching decision was made against the old container.
        $route->setContainer(new Container);

        $this->assertNull($controllerProperty->getValue($route));
        $this->assertNull($cacheDecisionProperty->getValue($route));
    }

    public function testSetContainerClearsCoroutineLocalController(): void
    {
        $firstContainer = new Container;
        $firstController = new BoundController;
        $firstContainer->bind(BoundController::class, fn () => $firstController);

        $route = $this->getRouter($firstContainer)
            ->get('foo', BoundController::class . '@index');

        $this->assertSame($firstController, $route->getController());

        $secondContainer = new Container;
        $secondController = new BoundController;
        $secondContainer->bind(BoundController::class, fn () => $secondController);

        $route->setContainer($secondContainer);

        $this->assertSame($secondController, $route->getController());
    }

    public function testPrepareForSerializationClearsControllerCache(): void
    {
        $router = $this->getRouter();
        $router->get('foo', UnboundController::class . '@index');

        $request = Request::create('foo', 'GET');
        $router->dispatch($request);

        $route = $request->route();
        $route->getController();

        $controllerProperty = new ReflectionProperty($route, 'controller');
        $cacheDecisionProperty = new ReflectionProperty($route, 'shouldCacheControllerOnRoute');

        $this->assertNotNull($controllerProperty->getValue($route));
        $this->assertNotNull($cacheDecisionProperty->getValue($route));

        $route->prepareForSerialization();

        $this->assertNull($controllerProperty->getValue($route));
        $this->assertNull($cacheDecisionProperty->getValue($route));
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

class UnboundController extends Controller
{
    public function index(): string
    {
        return 'unbound';
    }
}

#[Scoped]
class ScopedController extends Controller
{
    public function index(): string
    {
        return 'scoped';
    }
}

class BoundController extends Controller
{
    public function index(): string
    {
        return 'bound';
    }
}

class SingletonController extends Controller
{
    public function index(): string
    {
        return 'singleton';
    }
}
