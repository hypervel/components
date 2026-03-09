<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DefineCacheRoutesTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testCompiledRouteCollectionIsInstalledAfterDefineCacheRoutes()
    {
        $this->assertInstanceOf(
            RouteCollection::class,
            $this->app['router']->getRoutes()
        );

        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/compiled-check', fn () => 'ok');
PHP);

        $this->assertInstanceOf(
            CompiledRouteCollection::class,
            $this->app['router']->getRoutes()
        );
    }

    public function testCachedRoutesAreDispatchable()
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/hello', fn () => 'world');
PHP);

        $response = $this->get('/hello');
        $response->assertOk();
        $this->assertSame('world', $response->getContent());
    }

    public function testMultipleRoutesInSingleDefineCacheRoutes()
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/alpha', fn () => 'alpha_response');
Route::get('/beta', fn () => 'beta_response');
Route::post('/gamma', fn () => 'gamma_response');
PHP);

        $this->get('/alpha')->assertOk()->assertSee('alpha_response');
        $this->get('/beta')->assertOk()->assertSee('beta_response');
        $this->post('/gamma')->assertOk()->assertSee('gamma_response');
    }

    public function testNamedRoutesSurviveCaching()
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/named', fn () => 'named_response')->name('test.named');
PHP);

        /** @var Router $router */
        $router = $this->app['router'];
        $routes = $router->getRoutes();

        $this->assertNotNull($routes->getByName('test.named'));
        $this->assertSame('named', $routes->getByName('test.named')->uri());
    }

    public function testDefineCacheRoutesHasRunFlagIsSet()
    {
        $this->assertFalse($this->defineCacheRoutesHasRun);

        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/flag-check', fn () => 'ok');
PHP);

        $this->assertTrue($this->defineCacheRoutesHasRun);
    }

    public function testCacheFileExistsAfterDefineCacheRoutes()
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/cache-exists', fn () => 'ok');
PHP);

        $this->assertFileExists($this->app->getCachedRoutesPath());
    }

    public function testSetUpApplicationRoutesSkipsWhenRoutesCached()
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/cached-only', fn () => 'cached_only');
PHP);

        // routesAreCached() should return true
        $this->assertTrue($this->app->routesAreCached());

        // Routes from defineRoutes() should NOT be registered since
        // setUpApplicationRoutes returns early when routes are cached.
        // Only the cached /cached-only route should exist.
        $this->get('/cached-only')->assertOk();
    }

    public function testConsecutiveDefineCacheRoutesCallsUseCorrectClosures()
    {
        // First call — different route content
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/first', fn () => 'first_response');
PHP);

        $this->get('/first')->assertOk()->assertSee('first_response');

        // Second call in same test — must serialize the NEW closures, not stale
        // ones from the first call (validates the random file path fix)
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/second', fn () => 'second_response');
PHP);

        $this->get('/second')->assertOk()->assertSee('second_response');
        // First route should no longer exist after the second reload
        $this->get('/first')->assertNotFound();
    }
}
