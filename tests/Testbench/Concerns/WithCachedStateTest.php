<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\CachedState;
use Hypervel\Foundation\Testing\WithCachedConfig;
use Hypervel\Foundation\Testing\WithCachedRoutes;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Testbench\TestCase;

class WithCachedStateTest extends TestCase
{
    use WithCachedConfig;
    use WithCachedRoutes;

    protected int $routeDefinitions = 0;

    /** @var array<int, bool> */
    protected array $configCacheObservations = [];

    protected function setUp(): void
    {
        CachedState::$cachedConfig = null;
        CachedState::$cachedRoutes = null;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            CachedState::$cachedConfig = null;
            CachedState::$cachedRoutes = null;
        }
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->configCacheObservations[] = $app->bound('config_loaded_from_cache')
            && $app->make('config_loaded_from_cache') === true;
    }

    protected function defineRoutes(Router $router): void
    {
        ++$this->routeDefinitions;

        $router->get('/cached-testbench-state', static fn (): string => 'cached')
            ->name('cached-testbench-state');
    }

    public function testCachedStateIsRearmedBeforeSuccessiveTestbenchApplicationBoots(): void
    {
        $this->assertSame([false], $this->configCacheObservations);
        $this->assertSame(1, $this->routeDefinitions);
        $this->assertNotNull(
            $this->app->make(Router::class)->getRoutes()->getByName('cached-testbench-state')
        );

        // A full reload resets the cached-state traits and defines the routes again,
        // replacing the lifecycle this test is exercising.
        $this->refreshApplication();

        $routes = $this->app->make(Router::class)->getRoutes();

        $this->assertSame([false, true], $this->configCacheObservations);
        $this->assertSame(1, $this->routeDefinitions);
        $this->assertInstanceOf(CompiledRouteCollection::class, $routes);
        $this->assertNotNull($routes->getByName('cached-testbench-state'));
    }
}
