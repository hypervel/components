<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Routing\Route;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use ReflectionProperty;

class RouteCachingTest extends RoutingTestCase
{
    public function testWildcardCatchAllRoutes()
    {
        $this->defineCacheRoutes(file_get_contents(__DIR__ . '/Fixtures/wildcard_catch_all_routes.php'));

        $this->get('/foo')->assertSee('Regular route');
        $this->get('/bar')->assertSee('Wildcard route');
    }

    public function testRedirectRoutes()
    {
        $this->defineCacheRoutes(file_get_contents(__DIR__ . '/Fixtures/redirect_routes.php'));

        $this->post('/foo/1')->assertRedirect('/foo/1/bar');
        $this->get('/foo/1/bar')->assertSee('Redirect response');
        $this->get('/foo/1')->assertRedirect('/foo/1/bar');
    }

    public function testSetContainerInvalidatesControllerDispatcherCache()
    {
        $container1 = new Container;
        $dispatcher1 = m::mock(ControllerDispatcherContract::class);
        $container1->singleton(ControllerDispatcherContract::class, fn () => $dispatcher1);

        $route = new Route('GET', '/test', ['uses' => 'FooController@bar']);
        $route->setContainer($container1);

        $result1 = $route->controllerDispatcher();
        $this->assertSame($dispatcher1, $result1);

        // Swap to a new container with a different dispatcher.
        $container2 = new Container;
        $dispatcher2 = m::mock(ControllerDispatcherContract::class);
        $container2->singleton(ControllerDispatcherContract::class, fn () => $dispatcher2);

        $route->setContainer($container2);

        $result2 = $route->controllerDispatcher();
        $this->assertSame($dispatcher2, $result2);
        $this->assertNotSame($result1, $result2);
    }

    public function testSetContainerInvalidatesCallableDispatcherCache()
    {
        $dispatcher1 = m::mock(CallableDispatcherContract::class);
        $dispatcher1->shouldReceive('dispatch')->once()->andReturn('result1');

        $container1 = new Container;
        $container1->singleton(CallableDispatcherContract::class, fn () => $dispatcher1);

        $route = new Route('GET', '/test', ['uses' => fn () => null]);
        $route->setContainer($container1);

        // Populate the callable dispatcher cache by running the route.
        $route->run();
        $cached1 = $this->getProtectedProperty($route, 'callableDispatcher');
        $this->assertSame($dispatcher1, $cached1);

        // Swap container — cache should be invalidated.
        $dispatcher2 = m::mock(CallableDispatcherContract::class);
        $dispatcher2->shouldReceive('dispatch')->once()->andReturn('result2');

        $container2 = new Container;
        $container2->singleton(CallableDispatcherContract::class, fn () => $dispatcher2);

        $route->setContainer($container2);
        $this->assertNull($this->getProtectedProperty($route, 'callableDispatcher'));

        // Next run should use the new dispatcher.
        $route->run();
        $cached2 = $this->getProtectedProperty($route, 'callableDispatcher');
        $this->assertSame($dispatcher2, $cached2);
    }

    public function testSerializedCallableIsCachedAcrossCalls()
    {
        $closure = fn () => 'result';
        $serialized = serialize(SerializableClosure::unsigned($closure));

        $dispatcher = m::mock(CallableDispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturn('result');

        $container = new Container;
        $container->singleton(CallableDispatcherContract::class, fn () => $dispatcher);

        $route = new Route('GET', '/test', ['uses' => $closure]);
        $route->setContainer($container);

        // Simulate route caching: replace the live Closure with its serialized form.
        $route->action['uses'] = $serialized;

        // First call populates the cache.
        $route->run();

        $cachedCallable = $this->getProtectedProperty($route, 'callable');
        $this->assertInstanceOf(Closure::class, $cachedCallable);

        // Second call reuses the cached closure (no re-unserialization).
        $route->run();

        $this->assertSame($cachedCallable, $this->getProtectedProperty($route, 'callable'));
    }

    public function testSetActionInvalidatesCallableCache()
    {
        $dispatcher = m::mock(CallableDispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn('result');

        $container = new Container;
        $container->singleton(CallableDispatcherContract::class, fn () => $dispatcher);

        $route = new Route('GET', '/test', ['uses' => fn () => 'original']);
        $route->setContainer($container);

        // Populate the callable cache.
        $route->run();
        $this->assertNotNull($this->getProtectedProperty($route, 'callable'));

        // setAction should invalidate the cache.
        $route->setAction(['uses' => fn () => 'replaced']);
        $this->assertNull($this->getProtectedProperty($route, 'callable'));
    }

    public function testSerializedMissingHandlerIsCachedAcrossCalls()
    {
        $closure = fn () => 'missing handler';
        $serialized = serialize(SerializableClosure::unsigned($closure));

        $route = new Route('GET', '/test', ['uses' => fn () => null]);
        $route->action['missing'] = $serialized;

        $first = $route->getMissing();
        $second = $route->getMissing();

        $this->assertInstanceOf(Closure::class, $first);
        $this->assertSame($first, $second);
    }

    public function testMissingSetterInvalidatesMissingCache()
    {
        $closure = fn () => 'original';
        $serialized = serialize(SerializableClosure::unsigned($closure));

        $route = new Route('GET', '/test', ['uses' => fn () => null]);
        $route->action['missing'] = $serialized;

        $first = $route->getMissing();
        $this->assertInstanceOf(Closure::class, $first);

        // The missing() setter should invalidate the cache.
        $newClosure = fn () => 'replaced';
        $route->missing($newClosure);

        $second = $route->getMissing();
        $this->assertSame($newClosure, $second);
        $this->assertNotSame($first, $second);
    }

    public function testSetActionInvalidatesMissingCache()
    {
        $closure = fn () => 'missing handler';
        $serialized = serialize(SerializableClosure::unsigned($closure));

        $route = new Route('GET', '/test', ['uses' => fn () => null]);
        $route->action['missing'] = $serialized;

        // Populate the missing cache.
        $route->getMissing();
        $this->assertNotNull($this->getProtectedProperty($route, 'missing'));

        // setAction should invalidate.
        $route->setAction(['uses' => fn () => null]);
        $this->assertNull($this->getProtectedProperty($route, 'missing'));
    }

    public function testPrepareForSerializationClearsAllCaches()
    {
        $dispatcher = m::mock(CallableDispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn('result');

        $controllerDispatcher = m::mock(ControllerDispatcherContract::class);

        $container = new Container;
        $container->singleton(CallableDispatcherContract::class, fn () => $dispatcher);
        $container->singleton(ControllerDispatcherContract::class, fn () => $controllerDispatcher);

        $closure = fn () => 'result';
        $missingClosure = fn () => 'missing';

        $route = new Route('GET', '/test', ['uses' => $closure]);
        $route->setContainer($container);
        $route->action['missing'] = serialize(SerializableClosure::unsigned($missingClosure));

        // Populate all caches.
        $route->run();
        $route->getMissing();
        $route->controllerDispatcher();

        // Verify all caches are populated.
        $this->assertNotNull($this->getProtectedProperty($route, 'callable'));
        $this->assertNotNull($this->getProtectedProperty($route, 'missing'));
        $this->assertNotNull($this->getProtectedProperty($route, 'callableDispatcher'));
        $this->assertNotNull($this->getProtectedProperty($route, 'controllerDispatcher'));

        $route->prepareForSerialization();

        // All caches should be cleared.
        $this->assertNull($this->getProtectedProperty($route, 'callable'));
        $this->assertNull($this->getProtectedProperty($route, 'missing'));
        $this->assertNull($this->getProtectedProperty($route, 'callableDispatcher'));
        $this->assertNull($this->getProtectedProperty($route, 'controllerDispatcher'));
    }

    public function testFlushControllerClearsComputedMiddleware()
    {
        $route = new Route('GET', '/test', ['uses' => 'FooController@bar', 'middleware' => ['auth']]);

        // Populate the computed middleware cache.
        $route->gatherMiddleware();
        $this->assertNotNull($route->computedMiddleware);

        $route->flushController();
        $this->assertNull($route->computedMiddleware);
    }

    public function testCompiledRouteIsCachedOnInstance()
    {
        $route = new Route('GET', '/users/{id}', ['uses' => fn () => null]);

        $this->assertNull($route->compiled);

        // Trigger compilation.
        $route->ensureCompiled();
        $compiled = $route->compiled;

        $this->assertNotNull($compiled);

        // Second compilation returns the same instance.
        $route->ensureCompiled();
        $this->assertSame($compiled, $route->compiled);
    }

    public function testParameterNamesAreCachedOnInstance()
    {
        $route = new Route('GET', '/users/{id}/posts/{post}', ['uses' => fn () => null]);

        $this->assertNull($route->parameterNames);

        $names = $route->parameterNames();
        $this->assertSame(['id', 'post'], $names);

        // Cached — returns the same array.
        $this->assertSame($names, $route->parameterNames());
        $this->assertNotNull($route->parameterNames);
    }

    /**
     * Get a protected property value via reflection.
     */
    private function getProtectedProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }
}
