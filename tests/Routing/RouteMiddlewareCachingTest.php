<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteMiddlewareCachingTest;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Pipeline\PipeDescriptor;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Routing\Controller;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Route;
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

    public function testRouteDispatchCachesDescriptorsWithoutChangingGatheredMiddleware(): void
    {
        $router = $this->getRouter();
        $route = $router->get('foo', [
            'middleware' => TestMiddleware::class,
            'uses' => fn () => 'ok',
        ]);

        $response = $router->dispatch(Request::create('foo', 'GET'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame([TestMiddleware::class], $router->gatherRouteMiddleware($route));
        $this->assertContainsOnlyInstancesOf(PipeDescriptor::class, $route->middlewareDescriptors);
        $this->assertSame(TestMiddleware::class, $route->middlewareDescriptors[0]->name);
    }

    public function testRouteWithoutMiddlewareDoesNotBuildDescriptors(): void
    {
        $router = $this->getRouter();
        $route = $router->get('foo', fn () => 'ok');

        $response = $router->dispatch(Request::create('foo', 'GET'));

        $this->assertSame('ok', $response->getContent());
        $this->assertNull($route->middlewareDescriptors);
    }

    public function testDynamicGatheredMiddlewareIsNotReplacedByCachedDescriptors(): void
    {
        $container = new Container;
        $router = new DynamicMiddlewareRouter(new Dispatcher($container), $container);
        $route = $router->get('foo', fn () => 'ok');

        $first = $router->middlewareForRoute($route);
        $second = $router->middlewareForRoute($route);

        $this->assertSame(TestMiddleware::class, $first[0]->name);
        $this->assertSame(SecondTestMiddleware::class, $second[0]->name);
        $this->assertNull($route->middlewareDescriptors);
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
        $route->middlewareDescriptors = [new PipeDescriptor(TestMiddleware::class)];
        $this->assertNotNull($route->resolvedMiddleware);

        $route->flushController();

        $this->assertNull($route->middlewareDescriptors);
        $this->assertNull($route->resolvedMiddleware);
    }

    public function testPrepareForSerializationClearsResolvedMiddleware(): void
    {
        $router = $this->getRouter();

        $route = $router->get('foo', ['middleware' => TestMiddleware::class, 'uses' => function () {
            return 'ok';
        }]);

        $router->gatherRouteMiddleware($route);
        $route->middlewareDescriptors = [new PipeDescriptor(TestMiddleware::class)];
        $this->assertNotNull($route->resolvedMiddleware);

        $route->prepareForSerialization();

        $this->assertNull($route->middlewareDescriptors);
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
        $route->middlewareDescriptors = [new PipeDescriptor(TestMiddleware::class)];
        $middlewareDescriptors = $route->middlewareDescriptors;

        $route->setContainer($container);

        $this->assertSame($middlewareDescriptors, $route->middlewareDescriptors);
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
        $route->middlewareDescriptors = [new PipeDescriptor(TestMiddleware::class)];
        $this->assertNotNull($route->computedMiddleware);
        $this->assertNotNull($route->resolvedMiddleware);

        $route->setContainer(new Container);

        $this->assertNull($route->computedMiddleware);
        $this->assertNull($route->middlewareDescriptors);
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

class SecondTestMiddleware extends TestMiddleware
{
}

class DynamicMiddlewareRouter extends Router
{
    protected int $gatherCalls = 0;

    public function gatherRouteMiddleware(Route $route): array
    {
        return [++$this->gatherCalls === 1 ? TestMiddleware::class : SecondTestMiddleware::class];
    }

    public function middlewareForRoute(Route $route): array
    {
        return $this->middlewareFor($route);
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
