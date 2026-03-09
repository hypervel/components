<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ReflectionParameterCachingTest;

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
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 * @coversNothing
 */
class ReflectionParameterCachingTest extends RoutingTestCase
{
    protected function tearDown(): void
    {
        CallableDispatcher::flushCache();
        ControllerDispatcher::flushCache();

        parent::tearDown();
    }

    public function testClosureParametersAreCachedByObjectId()
    {
        $router = $this->getRouter();

        $closure = function (string $name) {
            return $name;
        };

        $router->get('foo/{name}', $closure);
        $router->dispatch(Request::create('foo/taylor', 'GET'));

        $cache = (new ReflectionProperty(CallableDispatcher::class, 'reflectionCache'))->getValue();

        $this->assertArrayHasKey(spl_object_id($closure), $cache);
        $this->assertIsArray($cache[spl_object_id($closure)]);
        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $cache[spl_object_id($closure)]);
    }

    public function testClosureParameterCacheReturnsSameArrayOnRepeatDispatch()
    {
        $router = $this->getRouter();

        $closure = function (string $name) {
            return $name;
        };

        $router->get('foo/{name}', $closure);

        $router->dispatch(Request::create('foo/taylor', 'GET'));
        $cacheAfterFirst = (new ReflectionProperty(CallableDispatcher::class, 'reflectionCache'))->getValue();

        $router->dispatch(Request::create('foo/dayle', 'GET'));
        $cacheAfterSecond = (new ReflectionProperty(CallableDispatcher::class, 'reflectionCache'))->getValue();

        $key = spl_object_id($closure);
        $this->assertSame($cacheAfterFirst[$key], $cacheAfterSecond[$key]);
    }

    public function testControllerParametersAreCachedByClassAndMethod()
    {
        $router = $this->getRouter();
        $router->get('foo/{name}', ParameterCachingController::class . '@show');

        $router->dispatch(Request::create('foo/taylor', 'GET'));

        $cache = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();

        $key = ParameterCachingController::class . '::show';
        $this->assertArrayHasKey($key, $cache);
        $this->assertIsArray($cache[$key]);
        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $cache[$key]);
    }

    public function testControllerParameterCacheReturnsSameArrayOnRepeatDispatch()
    {
        $router = $this->getRouter();
        $router->get('foo/{name}', ParameterCachingController::class . '@show');

        $router->dispatch(Request::create('foo/taylor', 'GET'));
        $cacheAfterFirst = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();

        $router->dispatch(Request::create('foo/dayle', 'GET'));
        $cacheAfterSecond = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();

        $key = ParameterCachingController::class . '::show';
        $this->assertSame($cacheAfterFirst[$key], $cacheAfterSecond[$key]);
    }

    public function testWarmReflectionCachesParameters()
    {
        ControllerDispatcher::warmReflection(ParameterCachingController::class, 'show');

        $cache = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();

        $key = ParameterCachingController::class . '::show';
        $this->assertArrayHasKey($key, $cache);
        $this->assertIsArray($cache[$key]);
        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $cache[$key]);
        $this->assertCount(1, $cache[$key]);
        $this->assertSame('name', $cache[$key][0]->getName());
    }

    public function testFlushCacheClearsCallableDispatcherCache()
    {
        $router = $this->getRouter();
        $router->get('foo', function () {
            return 'ok';
        });
        $router->dispatch(Request::create('foo', 'GET'));

        $cache = (new ReflectionProperty(CallableDispatcher::class, 'reflectionCache'))->getValue();
        $this->assertNotEmpty($cache);

        CallableDispatcher::flushCache();

        $cache = (new ReflectionProperty(CallableDispatcher::class, 'reflectionCache'))->getValue();
        $this->assertEmpty($cache);
    }

    public function testFlushCacheClearsControllerDispatcherCache()
    {
        ControllerDispatcher::warmReflection(ParameterCachingController::class, 'show');

        $cache = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();
        $this->assertNotEmpty($cache);

        ControllerDispatcher::flushCache();

        $cache = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();
        $this->assertEmpty($cache);
    }

    public function testControllerDispatchCorrectlyResolvesParameters()
    {
        $router = $this->getRouter();
        $router->get('foo/{name}', ParameterCachingController::class . '@show');

        $response = $router->dispatch(Request::create('foo/taylor', 'GET'));
        $this->assertSame('taylor', $response->getContent());

        // Second dispatch with different parameter — verifies cached parameters
        // are used for resolution (not stale resolved values).
        $response = $router->dispatch(Request::create('foo/dayle', 'GET'));
        $this->assertSame('dayle', $response->getContent());
    }

    public function testClosureDispatchCorrectlyResolvesParameters()
    {
        $router = $this->getRouter();
        $router->get('foo/{name}', function (string $name) {
            return $name;
        });

        $response = $router->dispatch(Request::create('foo/taylor', 'GET'));
        $this->assertSame('taylor', $response->getContent());

        $response = $router->dispatch(Request::create('foo/dayle', 'GET'));
        $this->assertSame('dayle', $response->getContent());
    }

    protected function getRouter(?Container $container = null): Router
    {
        $container ??= new Container();

        $router = new Router($container->make(Dispatcher::class), $container);

        $container->instance(Registrar::class, $router);

        $container->bind(ControllerDispatcherContract::class, fn ($app) => new ControllerDispatcher($app));
        $container->bind(CallableDispatcherContract::class, fn ($app) => new CallableDispatcher($app));

        return $router;
    }
}

class ParameterCachingController extends Controller
{
    public function show(string $name): string
    {
        return $name;
    }
}
