<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\RouteGroup;
use Hypervel\Routing\Router;
use Hypervel\Routing\UrlGenerator;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoutePortTest extends RoutingTestCase
{
    public function testPortDefaultsToNull(): void
    {
        $route = new Route('GET', '/foo', fn () => 'ok');

        $this->assertNull($route->getPort());
    }

    public function testPortCanBeSet(): void
    {
        $route = new Route('GET', '/foo', fn () => 'ok');
        $route->port(8080);

        $this->assertSame(8080, $route->getPort());
    }

    public function testPortFluentApiOnRouteRegistrar(): void
    {
        [$router] = $this->getRouter();

        $router->port(8080)->get('/foo', fn () => 'ok');

        $route = $router->getRoutes()->getRoutes()[0];

        $this->assertSame(8080, $route->getPort());
    }

    public function testPortGroupPropagation(): void
    {
        [$router] = $this->getRouter();

        $router->port(8080)->group(function ($router) {
            $router->get('/foo', fn () => 'ok');
            $router->get('/bar', fn () => 'ok');
        });

        $routes = $router->getRoutes()->getRoutes();

        $this->assertSame(8080, $routes[0]->getPort());
        $this->assertSame(8080, $routes[1]->getPort());
    }

    public function testInnerPortOverridesOuterGroup(): void
    {
        [$router] = $this->getRouter();

        $router->port(8080)->group(function ($router) {
            $router->get('/foo', fn () => 'outer');

            $router->port(8000)->group(function ($router) {
                $router->get('/bar', fn () => 'inner');
            });
        });

        $routes = $router->getRoutes()->getRoutes();

        $this->assertSame(8080, $routes[0]->getPort());
        $this->assertSame(8000, $routes[1]->getPort());
    }

    public function testPortGroupMergeInnerOverridesOuter(): void
    {
        $old = ['port' => 8080];
        $result = RouteGroup::merge(['port' => 8000], $old);

        $this->assertSame(8000, $result['port']);
    }

    public function testPortGroupMergeInheritsFromOuter(): void
    {
        $old = ['port' => 8080];
        $result = RouteGroup::merge([], $old);

        $this->assertSame(8080, $result['port']);
    }

    public function testRouteWithoutPortMatchesAnyPort(): void
    {
        [$router] = $this->getRouter();
        $router->get('/foo', fn () => 'ok');

        $response = $router->dispatch(Request::create('http://localhost:8080/foo', 'GET'));
        $this->assertSame('ok', $response->getContent());

        $response = $router->dispatch(Request::create('http://localhost:8000/foo', 'GET'));
        $this->assertSame('ok', $response->getContent());
    }

    public function testRouteWithPortMatchesCorrectPort(): void
    {
        [$router] = $this->getRouter();
        $router->port(8080)->get('/foo', fn () => 'ok');

        $response = $router->dispatch(Request::create('http://localhost:8080/foo', 'GET'));
        $this->assertSame('ok', $response->getContent());
    }

    public function testRouteWithPortRejectsWrongPort(): void
    {
        [$router] = $this->getRouter();
        $router->port(8080)->get('/foo', fn () => 'ok');

        $this->expectException(NotFoundHttpException::class);

        $router->dispatch(Request::create('http://localhost:8000/foo', 'GET'));
    }

    public function testSamePathDifferentPortThrowsLogicException(): void
    {
        $collection = new RouteCollection;
        $collection->add((new Route('GET', '/foo', fn () => 'a'))->port(8080));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register [GET foo] for multiple ports');

        $collection->add((new Route('GET', '/foo', fn () => 'b'))->port(8000));
    }

    public function testSamePathNullVsPortThrowsLogicException(): void
    {
        $collection = new RouteCollection;
        $collection->add(new Route('GET', '/foo', fn () => 'a'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register [GET foo] for multiple ports');

        $collection->add((new Route('GET', '/foo', fn () => 'b'))->port(8080));
    }

    public function testSamePathPortVsNullThrowsLogicException(): void
    {
        $collection = new RouteCollection;
        $collection->add((new Route('GET', '/foo', fn () => 'a'))->port(8080));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register [GET foo] for multiple ports');

        $collection->add(new Route('GET', '/foo', fn () => 'b'));
    }

    public function testSamePathSamePortAllowed(): void
    {
        $collection = new RouteCollection;
        $collection->add((new Route('GET', '/foo', ['uses' => fn () => 'a', 'as' => 'foo1']))->port(8080));
        $collection->add((new Route('GET', '/foo', ['uses' => fn () => 'b', 'as' => 'foo2']))->port(8080));

        // Second route overwrites first — normal behavior
        $this->assertCount(1, $collection->getRoutes());
    }

    public function testCompiledRoutePreservesPort(): void
    {
        [$router, $container] = $this->getRouter();
        $router->port(8080)->get('/foo', ['uses' => fn () => 'ok', 'as' => 'foo']);

        $routes = $router->getRoutes();
        $compiled = $routes->compile();

        $compiledCollection = new CompiledRouteCollection(
            $compiled['compiled'],
            $compiled['attributes']
        );
        $compiledCollection->setRouter($router);
        $compiledCollection->setContainer($container);

        $route = $compiledCollection->getByName('foo');

        $this->assertSame(8080, $route->getPort());
    }

    public function testCompiledRouteNameCacheBelongsToItsCollection(): void
    {
        [$firstRouter, $firstContainer] = $this->getRouter();
        $firstRouter->domain('first.test')->port(8080)->get('/first', [
            'uses' => 'FirstController@show',
            'as' => 'shared',
        ]);
        $firstCompiled = $firstRouter->getRoutes()->compile();
        $firstCollection = (new CompiledRouteCollection(
            $firstCompiled['compiled'],
            $firstCompiled['attributes'],
        ))->setRouter($firstRouter)->setContainer($firstContainer);

        [$secondRouter, $secondContainer] = $this->getRouter();
        $secondRouter->domain('second.test')->port(9090)->get('/second', [
            'uses' => 'SecondController@show',
            'as' => 'shared',
        ]);
        $secondCompiled = $secondRouter->getRoutes()->compile();
        $secondCollection = (new CompiledRouteCollection(
            $secondCompiled['compiled'],
            $secondCompiled['attributes'],
        ))->setRouter($secondRouter)->setContainer($secondContainer);

        $first = $firstCollection->getByName('shared');
        $second = $secondCollection->getByName('shared');

        $this->assertNotSame($first, $second);
        $this->assertSame($first, $firstCollection->getByName('shared'));
        $this->assertSame($second, $secondCollection->getByName('shared'));
        $this->assertSame(['first.test', 8080, 'first', 'FirstController@show'], [
            $first->getDomain(),
            $first->getPort(),
            $first->uri(),
            $first->getActionName(),
        ]);
        $this->assertSame(['second.test', 9090, 'second', 'SecondController@show'], [
            $second->getDomain(),
            $second->getPort(),
            $second->uri(),
            $second->getActionName(),
        ]);
    }

    public function testCompiledRouteCollectionRespectsPort(): void
    {
        [$router, $container] = $this->getRouter();
        $router->port(8080)->get('/foo', ['uses' => fn () => 'ok', 'as' => 'foo']);

        $routes = $router->getRoutes();
        $compiled = $routes->compile();

        $compiledCollection = new CompiledRouteCollection(
            $compiled['compiled'],
            $compiled['attributes']
        );
        $compiledCollection->setRouter($router);
        $compiledCollection->setContainer($container);

        // Correct port — matches
        $route = $compiledCollection->match(Request::create('http://localhost:8080/foo', 'GET'));
        $this->assertSame('foo', $route->getName());

        // Wrong port — 404
        $this->expectException(NotFoundHttpException::class);
        $compiledCollection->match(Request::create('http://localhost:8000/foo', 'GET'));
    }

    public function testRoutePortUsesTheListenerBehindAProxy(): void
    {
        [$router, $container] = $this->getRouter();
        $router->port(8000)->get('/orders', ['uses' => 'OrdersController@index', 'as' => 'orders']);
        $request = Request::create('https://example.com/orders');
        $request->server->set('SERVER_PORT', 8000);

        $plain = $router->getRoutes();

        foreach ([$plain, $plain->toCompiledRouteCollection($router, $container)] as $routes) {
            $this->assertSame('orders', $routes->match($request)->getName());

            $otherListener = Request::create('http://example.com:8000/orders');
            $otherListener->server->set('SERVER_PORT', 8080);

            try {
                $routes->match($otherListener);
                $this->fail('The route matched a different listener.');
            } catch (NotFoundHttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
    }

    public function testListenerScopedLinksUseThePublicOrigin(): void
    {
        $routes = new RouteCollection;
        $routes->add((new Route('GET', 'orders', ['as' => 'orders']))->port(8000));
        $routes->add((new Route('GET', 'orders', ['domain' => 'api.example.com', 'as' => 'api.orders']))->port(8000));
        $request = Request::create('https://example.com');
        $request->server->set('SERVER_PORT', 8000);

        $url = new UrlGenerator($routes, $request);
        $url->setKeyResolver(fn () => 'test-signing-key');
        $this->assertSame('https://example.com/orders', $url->route('orders'));
        $this->assertSame('https://api.example.com/orders', $url->route('api.orders'));

        $signed = $url->signedRoute('orders');
        $this->assertTrue($url->hasValidSignature(Request::create($signed)));
    }

    public function testRouteUrlGenerationWithoutPortUsesRequestPort(): void
    {
        $routes = new RouteCollection;
        $route = new Route(['GET'], 'foo', ['as' => 'noPort']);
        $routes->add($route);

        // Current request is on port 8000
        $url = new UrlGenerator($routes, Request::create('http://localhost:8000/'));

        // No port on route — should use request port
        $this->assertSame('http://localhost:8000/foo', $url->route('noPort'));
    }

    public function testRouteUrlGenerationPreservesForcedRootPath(): void
    {
        $routes = new RouteCollection;
        $route = (new Route(['GET'], 'foo', ['as' => 'portRoute']))->port(8080);
        $routes->add($route);

        // Forced root with a path component (e.g., reverse proxy path prefix)
        $url = new UrlGenerator($routes, Request::create('http://localhost:8000/'));
        $url->useOrigin('http://www.foo.com/subfolder');

        $this->assertSame('http://www.foo.com/subfolder/foo', $url->route('portRoute'));
    }

    public function testSignedLinksCanUseAnotherListenersPublicOrigin(): void
    {
        $routes = new RouteCollection;
        $routes->add((new Route('GET', 'orders', ['as' => 'orders']))->port(8080));
        $url = new UrlGenerator($routes, Request::create('https://example.com'));
        $url->setKeyResolver(fn () => 'test-signing-key');
        $url->formatHostUsing(fn (string $root, ?Route $route) => $route?->getPort() === 8080 ? 'https://admin.example.com' : $root);

        $signed = $url->signedRoute('orders');

        $this->assertStringStartsWith('https://admin.example.com/orders?', $signed);
        $this->assertTrue($url->hasValidSignature(Request::create($signed)));
    }

    public function testCompiledRouteDynamicAddWithDifferentPortThrows(): void
    {
        [$router, $container] = $this->getRouter();
        $router->port(8080)->get('/foo', ['uses' => fn () => 'ok', 'as' => 'foo']);

        $routes = $router->getRoutes();
        $compiled = $routes->compile();

        $compiledCollection = new CompiledRouteCollection(
            $compiled['compiled'],
            $compiled['attributes']
        );
        $compiledCollection->setRouter($router);
        $compiledCollection->setContainer($container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Cannot register [GET foo] for multiple ports');

        $compiledCollection->add((new Route('GET', 'foo', ['uses' => fn () => 'other', 'as' => 'foo2']))->port(8000));
    }

    public function testCompiledRouteDynamicAddWithSamePortAllowed(): void
    {
        [$router, $container] = $this->getRouter();
        $router->port(8080)->get('/foo', ['uses' => fn () => 'ok', 'as' => 'foo']);

        $routes = $router->getRoutes();
        $compiled = $routes->compile();

        $compiledCollection = new CompiledRouteCollection(
            $compiled['compiled'],
            $compiled['attributes']
        );
        $compiledCollection->setRouter($router);
        $compiledCollection->setContainer($container);

        $route = (new Route('GET', 'foo', ['uses' => fn () => 'other', 'as' => 'foo2']))->port(8080);
        $compiledCollection->add($route);

        $this->assertSame($route, $compiledCollection->getByName('foo2'));
    }

    /**
     * @return array{Router, Container}
     */
    protected function getRouter(): array
    {
        $container = new Container;
        $router = new Router($container->make(Dispatcher::class), $container);

        $container->instance(Registrar::class, $router);
        $container->bind(ControllerDispatcherContract::class, fn ($app) => new ControllerDispatcher($app));
        $container->bind(CallableDispatcherContract::class, fn ($app) => new CallableDispatcher($app));

        return [$router, $container];
    }
}
