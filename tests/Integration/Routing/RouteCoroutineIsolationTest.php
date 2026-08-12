<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;

use function Hypervel\Coroutine\parallel;

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

    public function testSiblingRoutesDoNotShareParametersOrOriginalParameters(): void
    {
        $users = new Route('GET', '/users/{id}', ['uses' => fn () => null]);
        $posts = new Route('GET', '/posts/{id}', ['uses' => fn () => null]);

        $users->bind(Request::create('/users/1'));
        $users->setParameter('id', 'changed-user');
        $posts->bind(Request::create('/posts/2'));
        $posts->setParameter('id', 'changed-post');

        $this->assertSame('changed-user', $users->parameter('id'));
        $this->assertSame('1', $users->originalParameter('id'));
        $this->assertSame('changed-post', $posts->parameter('id'));
        $this->assertSame('2', $posts->originalParameter('id'));
    }

    public function testSiblingRoutesDoNotShareBoundControllerInstances(): void
    {
        $container = new Container;
        $container->bind(RouteCoroutineIsolationTestController::class, fn () => new RouteCoroutineIsolationTestController);

        $first = (new Route('GET', '/first', ['uses' => RouteCoroutineIsolationTestController::class . '@index']))
            ->setContainer($container);
        $second = (new Route('GET', '/second', ['uses' => RouteCoroutineIsolationTestController::class . '@index']))
            ->setContainer($container);

        $firstController = $first->getController();
        $secondController = $second->getController();

        $this->assertNotSame($firstController, $secondController);
        $this->assertSame($firstController, $first->getController());
        $this->assertSame($secondController, $second->getController());

        $first->flushController();

        $this->assertNotSame($firstController, $first->getController());
        $this->assertSame($secondController, $second->getController());
    }

    public function testNestedRouteResponsesPreserveTheOuterRouteState(): void
    {
        $router = $this->app->make(Router::class);
        $observed = [];

        $router->get('/inner', function (Request $request) use (&$observed) {
            $route = $request->route();
            $route->setParameter('inner', 'value');

            $observed['inner_parameters'] = $route->parameters();
            $observed['inner_original_parameters'] = $route->originalParameters();

            return 'inner';
        })->name('inner');

        $router->get('/outer/{id}', function () use ($router, &$observed) {
            $outer = $router->current();

            $observed['current_before'] = $outer;
            $observed['container_before'] = $this->app->make(Route::class);

            $router->respondWithRoute('inner');

            $observed['current_after'] = $router->current();
            $observed['container_after'] = $this->app->make(Route::class);
            $observed['outer_parameters'] = $outer->parameters();
            $observed['outer_original_parameters'] = $outer->originalParameters();

            return 'outer';
        })->name('outer');

        $router->getRoutes()->refreshNameLookups();

        $request = Request::create('/outer/42');
        RequestContext::set($request);

        $response = $router->dispatch($request);
        $outer = $router->getRoutes()->getByName('outer');

        $this->assertSame('outer', $response->getContent());
        $this->assertSame($outer, $observed['current_before']);
        $this->assertSame($outer, $observed['current_after']);
        $this->assertSame($outer, $observed['container_before']);
        $this->assertSame($outer, $observed['container_after']);
        $this->assertSame(['inner' => 'value'], $observed['inner_parameters']);
        $this->assertSame([], $observed['inner_original_parameters']);
        $this->assertSame(['id' => '42'], $observed['outer_parameters']);
        $this->assertSame(['id' => '42'], $observed['outer_original_parameters']);
    }
}

class RouteCoroutineIsolationTestController
{
    public function index(): string
    {
        return 'ok';
    }
}
