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
    public function testPortDefaultsToNull()
    {
        $route = new Route('GET', '/foo', fn () => 'ok');

        $this->assertNull($route->getPort());
    }

    public function testPortCanBeSet()
    {
        $route = new Route('GET', '/foo', fn () => 'ok');
        $route->port(8080);

        $this->assertSame(8080, $route->getPort());
    }

    public function testPortFluentApiOnRouteRegistrar()
    {
        [$router] = $this->getRouter();

        $router->port(8080)->get('/foo', fn () => 'ok');

        $route = $router->getRoutes()->getRoutes()[0];

        $this->assertSame(8080, $route->getPort());
    }

    public function testPortGroupPropagation()
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

    public function testInnerPortOverridesOuterGroup()
    {
        [$router] = $this->getRouter();

        $router->port(8080)->group(function ($router) {
            $router->get('/foo', fn () => 'outer');

            $router->port(9501)->group(function ($router) {
                $router->get('/bar', fn () => 'inner');
            });
        });

        $routes = $router->getRoutes()->getRoutes();

        $this->assertSame(8080, $routes[0]->getPort());
        $this->assertSame(9501, $routes[1]->getPort());
    }

    public function testPortGroupMergeInnerOverridesOuter()
    {
        $old = ['port' => 8080];
        $result = RouteGroup::merge(['port' => 9501], $old);

        $this->assertSame(9501, $result['port']);
    }

    public function testPortGroupMergeInheritsFromOuter()
    {
        $old = ['port' => 8080];
        $result = RouteGroup::merge([], $old);

        $this->assertSame(8080, $result['port']);
    }

    public function testRouteWithoutPortMatchesAnyPort()
    {
        [$router] = $this->getRouter();
        $router->get('/foo', fn () => 'ok');

        $response = $router->dispatch(Request::create('http://localhost:8080/foo', 'GET'));
        $this->assertSame('ok', $response->getContent());

        $response = $router->dispatch(Request::create('http://localhost:9501/foo', 'GET'));
        $this->assertSame('ok', $response->getContent());
    }

    public function testRouteWithPortMatchesCorrectPort()
    {
        [$router] = $this->getRouter();
        $router->port(8080)->get('/foo', fn () => 'ok');

        $response = $router->dispatch(Request::create('http://localhost:8080/foo', 'GET'));
        $this->assertSame('ok', $response->getContent());
    }

    public function testRouteWithPortRejectsWrongPort()
    {
        [$router] = $this->getRouter();
        $router->port(8080)->get('/foo', fn () => 'ok');

        $this->expectException(NotFoundHttpException::class);

        $router->dispatch(Request::create('http://localhost:9501/foo', 'GET'));
    }

    public function testSamePathDifferentPortThrowsLogicException()
    {
        $collection = new RouteCollection;
        $collection->add((new Route('GET', '/foo', fn () => 'a'))->port(8080));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot register [GET foo] for multiple ports');

        $collection->add((new Route('GET', '/foo', fn () => 'b'))->port(9501));
    }

    public function testSamePathNullVsPortThrowsLogicException()
    {
        $collection = new RouteCollection;
        $collection->add(new Route('GET', '/foo', fn () => 'a'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot register [GET foo] for multiple ports');

        $collection->add((new Route('GET', '/foo', fn () => 'b'))->port(8080));
    }

    public function testSamePathPortVsNullThrowsLogicException()
    {
        $collection = new RouteCollection;
        $collection->add((new Route('GET', '/foo', fn () => 'a'))->port(8080));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot register [GET foo] for multiple ports');

        $collection->add(new Route('GET', '/foo', fn () => 'b'));
    }

    public function testSamePathSamePortAllowed()
    {
        $collection = new RouteCollection;
        $collection->add((new Route('GET', '/foo', ['uses' => fn () => 'a', 'as' => 'foo1']))->port(8080));
        $collection->add((new Route('GET', '/foo', ['uses' => fn () => 'b', 'as' => 'foo2']))->port(8080));

        // Second route overwrites first — normal behavior
        $this->assertCount(1, $collection->getRoutes());
    }

    public function testCompiledRoutePreservesPort()
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

    public function testCompiledRouteCollectionRespectsPort()
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
        $compiledCollection->match(Request::create('http://localhost:9501/foo', 'GET'));
    }

    public function testRouteUrlGenerationUsesRoutePort()
    {
        $routes = new RouteCollection;
        $route = (new Route(['GET'], 'foo', ['as' => 'portRoute']))->port(8080);
        $routes->add($route);

        // Current request is on port 9501
        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));

        // Absolute URL should use route's port (8080), not request's port (9501)
        $this->assertSame('http://localhost:8080/foo', $url->route('portRoute'));
    }

    public function testRouteUrlGenerationOmitsDefaultPort()
    {
        $routes = new RouteCollection;
        $route = (new Route(['GET'], 'foo', ['as' => 'portRoute']))->port(80);
        $routes->add($route);

        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));

        // Port 80 on HTTP should be omitted
        $this->assertSame('http://localhost/foo', $url->route('portRoute'));
    }

    public function testRouteUrlGenerationWithHttpsAndPort443()
    {
        $routes = new RouteCollection;
        $route = new Route(['GET'], 'foo', ['as' => 'secureRoute', 'https']);
        $route->port(443);
        $routes->add($route);

        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));

        // Port 443 on HTTPS should be omitted
        $this->assertSame('https://localhost/foo', $url->route('secureRoute'));
    }

    public function testRouteUrlGenerationWithoutPortUsesRequestPort()
    {
        $routes = new RouteCollection;
        $route = new Route(['GET'], 'foo', ['as' => 'noPort']);
        $routes->add($route);

        // Current request is on port 9501
        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));

        // No port on route — should use request port
        $this->assertSame('http://localhost:9501/foo', $url->route('noPort'));
    }

    public function testRelativeUrlIgnoresPort()
    {
        $routes = new RouteCollection;
        $route = (new Route(['GET'], 'foo', ['as' => 'portRoute']))->port(8080);
        $routes->add($route);

        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));

        // Relative URL has no host/port
        $this->assertSame('/foo', $url->route('portRoute', [], false));
    }

    public function testRouteUrlGenerationPreservesForcedRootPath()
    {
        $routes = new RouteCollection;
        $route = (new Route(['GET'], 'foo', ['as' => 'portRoute']))->port(8080);
        $routes->add($route);

        // Forced root with a path component (e.g., reverse proxy path prefix)
        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));
        $url->useOrigin('http://www.foo.com/subfolder');

        $this->assertSame('http://www.foo.com:8080/subfolder/foo', $url->route('portRoute'));
    }

    public function testSignedRouteUsesRoutePort()
    {
        $routes = new RouteCollection;
        $route = (new Route(['GET'], 'foo', ['as' => 'portRoute']))->port(8080);
        $routes->add($route);

        $url = new UrlGenerator($routes, Request::create('http://localhost:9501/'));
        $url->setKeyResolver(fn () => 'test-signing-key');

        $signed = $url->signedRoute('portRoute');

        // Signed URL should use route port, not request port
        $this->assertStringContainsString('localhost:8080', $signed);
        $this->assertStringNotContainsString('9501', $signed);

        // Signature should be valid when verified against the correct URL
        $this->assertTrue($url->hasValidSignature(
            Request::create($signed)
        ));
    }

    public function testCompiledRouteDynamicAddWithDifferentPortThrows()
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
        $this->expectExceptionMessage('Cannot register [GET foo] for multiple ports');

        $compiledCollection->add((new Route('GET', 'foo', ['uses' => fn () => 'other', 'as' => 'foo2']))->port(9501));
    }

    public function testCompiledRouteDynamicAddWithSamePortAllowed()
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

        // Same port — no exception
        $compiledCollection->add((new Route('GET', 'foo', ['uses' => fn () => 'other', 'as' => 'foo2']))->port(8080));

        $this->assertTrue(true);
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
