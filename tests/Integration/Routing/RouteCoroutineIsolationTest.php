<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;

use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class RouteCoroutineIsolationTest extends RoutingTestCase
{
    public function testParametersAreIsolatedBetweenCoroutines()
    {
        $route = new Route('GET', '/users/{id}', ['uses' => fn () => null]);

        $results = parallel([
            function () use ($route) {
                $request = Request::create('/users/1');
                $route->bind($request);
                usleep(1000);

                return $route->parameter('id');
            },
            function () use ($route) {
                $request = Request::create('/users/2');
                $route->bind($request);
                usleep(1000);

                return $route->parameter('id');
            },
        ]);

        // Each coroutine should see its own parameter value.
        $this->assertContains('1', $results);
        $this->assertContains('2', $results);
    }

    public function testOriginalParametersAreIsolatedBetweenCoroutines()
    {
        $route = new Route('GET', '/users/{id}', ['uses' => fn () => null]);

        $results = parallel([
            function () use ($route) {
                $request = Request::create('/users/10');
                $route->bind($request);
                // Mutate the parameter — original should be unaffected.
                $route->setParameter('id', 'mutated-10');
                usleep(1000);

                return [
                    'current' => $route->parameter('id'),
                    'original' => $route->originalParameter('id'),
                ];
            },
            function () use ($route) {
                $request = Request::create('/users/20');
                $route->bind($request);
                $route->setParameter('id', 'mutated-20');
                usleep(1000);

                return [
                    'current' => $route->parameter('id'),
                    'original' => $route->originalParameter('id'),
                ];
            },
        ]);

        // Each coroutine sees its own current and original parameters.
        $this->assertContains(['current' => 'mutated-10', 'original' => '10'], $results);
        $this->assertContains(['current' => 'mutated-20', 'original' => '20'], $results);
    }

    public function testSetParameterIsIsolatedBetweenCoroutines()
    {
        $route = new Route('GET', '/users/{id}', ['uses' => fn () => null]);

        $results = parallel([
            function () use ($route) {
                $request = Request::create('/users/1');
                $route->bind($request);
                $route->setParameter('id', 'replaced-by-coroutine-1');
                usleep(1000);

                return $route->parameter('id');
            },
            function () use ($route) {
                $request = Request::create('/users/2');
                $route->bind($request);
                $route->setParameter('id', 'replaced-by-coroutine-2');
                usleep(1000);

                return $route->parameter('id');
            },
        ]);

        $this->assertContains('replaced-by-coroutine-1', $results);
        $this->assertContains('replaced-by-coroutine-2', $results);
    }

    public function testForgetParameterIsIsolatedBetweenCoroutines()
    {
        $route = new Route('GET', '/users/{id}', ['uses' => fn () => null]);

        $results = parallel([
            function () use ($route) {
                $request = Request::create('/users/1');
                $route->bind($request);
                $route->forgetParameter('id');
                usleep(1000);

                return $route->hasParameter('id');
            },
            function () use ($route) {
                $request = Request::create('/users/2');
                $route->bind($request);
                usleep(1000);

                // This coroutine should still have the parameter.
                return $route->hasParameter('id');
            },
        ]);

        // One coroutine forgot it, the other kept it.
        $this->assertContains(true, $results);
        $this->assertContains(false, $results);
    }

    public function testControllerInstancesAreIsolatedBetweenCoroutines()
    {
        $container = new Container;
        // Use bind() so each make() returns a fresh instance (no auto-singleton).
        $container->bind(RouteCoroutineIsolationTestController::class, fn () => new RouteCoroutineIsolationTestController);

        $route = new Route('GET', '/test', ['uses' => RouteCoroutineIsolationTestController::class . '@index']);
        $route->setContainer($container);

        $results = parallel([
            function () use ($route) {
                $controller = $route->getController();
                usleep(1000);

                // Same coroutine gets the same instance (cached in Context).
                return [
                    'id' => spl_object_id($controller),
                    'same' => $controller === $route->getController(),
                ];
            },
            function () use ($route) {
                $controller = $route->getController();
                usleep(1000);

                return [
                    'id' => spl_object_id($controller),
                    'same' => $controller === $route->getController(),
                ];
            },
        ]);

        // Each coroutine got its own controller instance.
        $this->assertNotSame($results[0]['id'], $results[1]['id']);

        // Within each coroutine, repeated calls returned the same instance.
        $this->assertTrue($results[0]['same']);
        $this->assertTrue($results[1]['same']);
    }

    public function testHasParametersReturnsFalseInUnboundCoroutine()
    {
        $route = new Route('GET', '/users/{id}', ['uses' => fn () => null]);

        $results = parallel([
            function () use ($route) {
                $request = Request::create('/users/1');
                $route->bind($request);

                return $route->hasParameters();
            },
            function () use ($route) {
                // This coroutine never binds the route.
                return $route->hasParameters();
            },
        ]);

        $this->assertContains(true, $results);
        $this->assertContains(false, $results);
    }

    public function testRouteClassContainerBindingIsIsolatedBetweenCoroutines()
    {
        $router = $this->app->make(\Hypervel\Routing\Router::class);

        $router->get('/users/{id}', fn () => 'users')->name('users');
        $router->get('/posts/{id}', fn () => 'posts')->name('posts');

        $results = parallel([
            function () use ($router) {
                $request = Request::create('/users/1');
                $router->dispatch($request);
                usleep(10000); // Yield to let the other coroutine dispatch.

                return $this->app->make(Route::class)->uri();
            },
            function () use ($router) {
                usleep(5000); // Start slightly after the first coroutine.
                $request = Request::create('/posts/2');
                $router->dispatch($request);

                return $this->app->make(Route::class)->uri();
            },
        ]);

        // Each coroutine should resolve its own matched route from the container.
        $this->assertContains('users/{id}', $results);
        $this->assertContains('posts/{id}', $results);
    }
}

class RouteCoroutineIsolationTestController
{
    public function index(): string
    {
        return 'ok';
    }
}
