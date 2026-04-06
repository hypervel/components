<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Container\Container;
use Hypervel\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Controllers\HasMiddleware;
use Hypervel\Routing\Controllers\Middleware;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Tests\TestCase;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Tests for route pre-warming at server boot.
 *
 * Pre-warming populates static caches (compiled regex, middleware stacks,
 * reflection data) before fork so workers inherit them via copy-on-write.
 * These tests verify each cache is populated correctly.
 *
 * @internal
 * @coversNothing
 */
class RouteWarmupTest extends TestCase
{
    public function testWarmUpPopulatesCompiledRegexOnRoutes(): void
    {
        $router = $this->createRouter();

        $router->get('/users/{id}', [WarmupControllerWithHasMiddleware::class, 'index'])->name('users.index');
        $router->get('/static', [WarmupControllerWithHasMiddleware::class, 'show'])->name('static');

        $router->compileAndWarm();

        $routes = $router->getRoutes();

        foreach ($routes->getWarmableRoutes() as $route) {
            $this->assertNotNull(
                $route->compiled,
                "Route '{$route->uri()}' should have compiled regex after warmUp"
            );
        }
    }

    public function testWarmUpPopulatesMiddlewareForHasMiddlewareControllers(): void
    {
        $router = $this->createRouter();

        $router->get('/test', [WarmupControllerWithHasMiddleware::class, 'index'])->name('has-middleware');

        $router->compileAndWarm();

        $route = $router->getRoutes()->getByName('has-middleware');

        $this->assertNotNull(
            $route->computedMiddleware,
            'Route with HasMiddleware controller should have pre-warmed middleware'
        );
        $this->assertContains('auth', $route->computedMiddleware);
        $this->assertContains('log', $route->computedMiddleware);
    }

    public function testWarmUpPopulatesMiddlewareForAttributeBasedControllers(): void
    {
        $router = $this->createRouter();

        $router->get('/attr-index', [WarmupControllerWithAttributes::class, 'index'])->name('attr.index');
        $router->get('/attr-show', [WarmupControllerWithAttributes::class, 'show'])->name('attr.show');

        $router->compileAndWarm();

        $indexRoute = $router->getRoutes()->getByName('attr.index');
        $showRoute = $router->getRoutes()->getByName('attr.show');

        // Attribute-based controllers (no HasMiddleware, no getMiddleware) should be pre-warmed.
        $this->assertNotNull(
            $indexRoute->computedMiddleware,
            'Attribute-based route should have pre-warmed middleware'
        );

        // Index gets class-level 'throttle' + method-level 'verified' + 'only-index' (only: ['index']),
        // but NOT 'except-index' (except: ['index']).
        $this->assertContains('throttle', $indexRoute->computedMiddleware);
        $this->assertContains('verified', $indexRoute->computedMiddleware);
        $this->assertContains('only-index', $indexRoute->computedMiddleware);
        $this->assertNotContains('except-index', $indexRoute->computedMiddleware);

        // Show gets class-level 'throttle' + 'except-index' (except: ['index']),
        // but NOT method-level 'verified' (on index only) or 'only-index'.
        $this->assertContains('throttle', $showRoute->computedMiddleware);
        $this->assertContains('except-index', $showRoute->computedMiddleware);
        $this->assertNotContains('verified', $showRoute->computedMiddleware);
        $this->assertNotContains('only-index', $showRoute->computedMiddleware);
    }

    public function testWarmUpSkipsMiddlewareForLegacyGetMiddlewareControllers(): void
    {
        $router = $this->createRouter();

        $router->get('/legacy', [WarmupControllerWithGetMiddleware::class, 'index'])->name('legacy');

        $router->compileAndWarm();

        $route = $router->getRoutes()->getByName('legacy');

        // Legacy controllers using getMiddleware() are NOT pre-warmed because
        // getMiddleware() requires getController() which uses Context (not
        // available at server boot before coroutines exist).
        $this->assertNull(
            $route->computedMiddleware,
            'Legacy getMiddleware() controller should NOT be pre-warmed (requires Context)'
        );
    }

    public function testWarmUpPopulatesControllerDispatcherReflectionCache(): void
    {
        $router = $this->createRouter();

        $router->get('/reflect', [WarmupControllerWithHasMiddleware::class, 'index'])->name('reflect');

        $router->compileAndWarm();

        // The reflection cache is static — verify it was populated by checking
        // that calling warmReflection again returns instantly (cache hit).
        // We can't directly read the protected static, but we can verify
        // the method exists in cache by checking a new reflection won't be created.
        $reflectionProperty = new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache');
        $cache = $reflectionProperty->getValue();

        $key = WarmupControllerWithHasMiddleware::class . '::index';
        $this->assertArrayHasKey($key, $cache);
        $this->assertIsArray($cache[$key]);
        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $cache[$key]);
    }

    public function testWarmUpPopulatesRouteSignatureParametersCache(): void
    {
        $router = $this->createRouter();

        $router->get('/sig/{id}', [WarmupControllerWithHasMiddleware::class, 'index'])->name('sig');

        $router->compileAndWarm();

        // Verify the cache is populated by checking the internal static.
        $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'cache');
        $cache = $reflectionProperty->getValue();

        $key = WarmupControllerWithHasMiddleware::class . '@index';
        $this->assertArrayHasKey($key, $cache);
    }

    public function testWarmUpSkipsNonControllerRoutes(): void
    {
        $router = $this->createRouter();

        $router->get('/closure', fn () => 'ok')->name('closure');

        $router->compileAndWarm();

        $route = $router->getRoutes()->getByName('closure');

        // Compiled regex should still be populated even for closure routes.
        $this->assertNotNull($route->compiled);

        // But middleware pre-warming should be skipped (no controller class).
        // Closure routes have no controllerMiddleware, but gatherMiddleware() is not called.
        $this->assertNull($route->computedMiddleware);

        // Controller reflection caches should be empty — no controllers registered.
        $reflectionProperty = new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache');
        $cache = $reflectionProperty->getValue();
        $this->assertEmpty($cache);
    }

    public function testCompileAndWarmConvertsRouteCollectionToCompiled(): void
    {
        $router = $this->createRouter();

        $router->get('/users/{id}', [WarmupControllerWithHasMiddleware::class, 'index'])->name('users.index');

        // Before compileAndWarm, routes are in a RouteCollection (uncompiled).
        $this->assertInstanceOf(RouteCollection::class, $router->getRoutes());

        $router->compileAndWarm();

        // After compileAndWarm, routes should be in a CompiledRouteCollection.
        $this->assertInstanceOf(CompiledRouteCollection::class, $router->getRoutes());
    }

    public function testCompileAndWarmIsIdempotent(): void
    {
        $router = $this->createRouter();

        $router->get('/test', [WarmupControllerWithHasMiddleware::class, 'index'])->name('test');

        $router->compileAndWarm();

        $routes1 = $router->getRoutes();
        $route1 = $routes1->getByName('test');
        $compiled1 = $route1->compiled;

        // Calling again should not replace or break anything.
        $router->compileAndWarm();

        $routes2 = $router->getRoutes();
        $route2 = $routes2->getByName('test');

        // Same route collection instance (already compiled, skips recompilation).
        $this->assertSame($routes1, $routes2);

        // Same compiled regex instance on the same route object.
        $this->assertSame($compiled1, $route2->compiled);
    }

    public function testFlushRoutingCachesClearsAllStaticCaches(): void
    {
        $router = $this->createRouter();

        $router->get('/users/{id}', [WarmupControllerWithHasMiddleware::class, 'index'])->name('test');
        $router->compileAndWarm();

        // Verify caches are populated.
        $controllerCache = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();
        $sigCache = (new ReflectionProperty(RouteSignatureParameters::class, 'cache'))->getValue();
        $this->assertNotEmpty($controllerCache);
        $this->assertNotEmpty($sigCache);

        // Replace routes (triggers flushRoutingCaches via setRoutes).
        $newCollection = new RouteCollection;
        $newCollection->add(new Route('GET', '/new', ['uses' => fn () => 'ok', 'as' => 'new']));
        $router->setRoutes($newCollection);

        // All static caches should be flushed.
        $controllerCache = (new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache'))->getValue();
        $sigCache = (new ReflectionProperty(RouteSignatureParameters::class, 'cache'))->getValue();
        $this->assertEmpty($controllerCache);
        $this->assertEmpty($sigCache);
    }

    public function testWarmUpHandlesMultipleControllerMethodsOnSameController(): void
    {
        $router = $this->createRouter();

        $router->get('/index', [WarmupControllerWithHasMiddleware::class, 'index'])->name('index');
        $router->get('/show', [WarmupControllerWithHasMiddleware::class, 'show'])->name('show');

        $router->compileAndWarm();

        $reflectionProperty = new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache');
        $cache = $reflectionProperty->getValue();

        $this->assertArrayHasKey(WarmupControllerWithHasMiddleware::class . '::index', $cache);
        $this->assertArrayHasKey(WarmupControllerWithHasMiddleware::class . '::show', $cache);
    }

    public function testWarmUpDoesNotWarmMiddlewareForClosureRoutes(): void
    {
        $router = $this->createRouter();

        $router->get('/a', fn () => 'a')->middleware('auth')->name('a');
        $router->get('/b', fn () => 'b')->name('b');

        $router->compileAndWarm();

        // Closure routes should NOT have computedMiddleware populated by warmUp,
        // even if they have route-level middleware. warmUp only calls
        // gatherMiddleware() for controller routes that are safe to pre-warm.
        $routeA = $router->getRoutes()->getByName('a');
        $routeB = $router->getRoutes()->getByName('b');

        $this->assertNull($routeA->computedMiddleware);
        $this->assertNull($routeB->computedMiddleware);
    }

    public function testWarmUpHandlesMixedControllerTypes(): void
    {
        $router = $this->createRouter();

        $router->get('/has', [WarmupControllerWithHasMiddleware::class, 'index'])->name('has');
        $router->get('/attr', [WarmupControllerWithAttributes::class, 'index'])->name('attr');
        $router->get('/legacy', [WarmupControllerWithGetMiddleware::class, 'index'])->name('legacy');
        $router->get('/closure', fn () => 'ok')->name('closure');

        $router->compileAndWarm();

        // HasMiddleware controller — pre-warmed.
        $this->assertNotNull($router->getRoutes()->getByName('has')->computedMiddleware);

        // Attribute-based controller — pre-warmed.
        $this->assertNotNull($router->getRoutes()->getByName('attr')->computedMiddleware);

        // Legacy getMiddleware() controller — NOT pre-warmed.
        $this->assertNull($router->getRoutes()->getByName('legacy')->computedMiddleware);

        // Closure — NOT pre-warmed.
        $this->assertNull($router->getRoutes()->getByName('closure')->computedMiddleware);

        // But ALL routes should have compiled regex.
        foreach ($router->getRoutes()->getWarmableRoutes() as $route) {
            $this->assertNotNull($route->compiled, "Route '{$route->uri()}' should be compiled");
        }
    }

    public function testWarmUpPopulatesReflectionForAttributeControllers(): void
    {
        $router = $this->createRouter();

        $router->get('/attr', [WarmupControllerWithAttributes::class, 'index'])->name('attr');

        $router->compileAndWarm();

        $reflectionProperty = new ReflectionProperty(ControllerDispatcher::class, 'reflectionCache');
        $cache = $reflectionProperty->getValue();

        $key = WarmupControllerWithAttributes::class . '::index';
        $this->assertArrayHasKey($key, $cache);
    }

    public function testWarmUpWithRouteGroupMiddleware(): void
    {
        $router = $this->createRouter();

        $router->middleware('auth')->group(function () use ($router) {
            $router->get('/grouped', [WarmupControllerWithHasMiddleware::class, 'index'])->name('grouped');
        });

        $router->compileAndWarm();

        $route = $router->getRoutes()->getByName('grouped');

        // gatherMiddleware() merges route-level and controller-level middleware.
        $this->assertNotNull($route->computedMiddleware);

        // 'auth' is route-level, 'auth' and 'log' are from HasMiddleware.
        // After uniqueMiddleware(), should contain all unique values.
        $this->assertContains('auth', $route->computedMiddleware);
        $this->assertContains('log', $route->computedMiddleware);
    }

    /**
     * Create a fresh Router instance with a container.
     */
    private function createRouter(): Router
    {
        $container = new Container;
        $container->singleton('events', fn () => new \Hypervel\Events\Dispatcher($container));

        $router = new Router($container->make('events'), $container);
        $container->instance('router', $router);

        return $router;
    }
}

/**
 * Controller using the HasMiddleware interface — safe to pre-warm.
 */
class WarmupControllerWithHasMiddleware implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('auth'),
            new Middleware('log'),
        ];
    }

    public function index(string $id = ''): string
    {
        return 'index';
    }

    public function show(): string
    {
        return 'show';
    }
}

/**
 * Controller using #[Middleware] attributes — safe to pre-warm via reflection.
 */
#[MiddlewareAttribute('throttle')]
#[MiddlewareAttribute('only-index', only: ['index'])]
#[MiddlewareAttribute('except-index', except: ['index'])]
class WarmupControllerWithAttributes
{
    #[MiddlewareAttribute('verified')]
    public function index(): string
    {
        return 'index';
    }

    public function show(): string
    {
        return 'show';
    }
}

/**
 * Controller using the legacy getMiddleware() pattern — NOT safe to pre-warm.
 *
 * getMiddleware() requires getController() which uses Context (coroutine-local
 * storage). At server boot, no coroutine exists, so this path would fail.
 */
class WarmupControllerWithGetMiddleware
{
    /**
     * @return array<int, array{middleware: string, options: array}>
     */
    public function getMiddleware(): array
    {
        return [
            ['middleware' => 'cache', 'options' => []],
        ];
    }

    public function index(): string
    {
        return 'index';
    }
}
