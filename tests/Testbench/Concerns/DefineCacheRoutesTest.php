<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Testbench\TestCase;
use Throwable;

class DefineCacheRoutesTest extends TestCase
{
    public function testCompiledRouteCollectionIsInstalledAfterDefineCacheRoutes(): void
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

    public function testCachedRoutesAreDispatchable(): void
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

    public function testMultipleRoutesInSingleDefineCacheRoutes(): void
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

    public function testNamedRoutesSurviveCaching(): void
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

    public function testDefineCacheRoutesHasRunFlagIsSet(): void
    {
        $this->assertFalse($this->requireApplicationCachedRoutesHasRun);

        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/flag-check', fn () => 'ok');
PHP);

        $this->assertTrue($this->requireApplicationCachedRoutesHasRun);
    }

    public function testDefineCacheRoutesTracksOwnedRouteFile(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/first-file', fn () => 'first');
PHP, false);

        $this->assertCount(1, $this->testbenchRouteFiles);
        $this->assertFileExists($this->testbenchRouteFiles[0]);
    }

    public function testDefineCacheRoutesDeletesOnlyOwnedRouteFiles(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/owned-file', fn () => 'owned');
PHP, false);

        $ownedRouteFile = $this->testbenchRouteFiles[0];
        $siblingRouteFile = $this->app->basePath('routes/testbench-sibling.php');

        file_put_contents($siblingRouteFile, '<?php');

        try {
            $this->assertFileExists($ownedRouteFile);
            $this->assertFileExists($siblingRouteFile);

            $this->callBeforeApplicationDestroyedCallbacks();

            $this->assertFileDoesNotExist($ownedRouteFile);
            $this->assertFileExists($siblingRouteFile);
        } finally {
            @unlink($siblingRouteFile);
        }
    }

    public function testDefineCacheRoutesCleansRouteFileWhenRouteCacheFails(): void
    {
        try {
            $this->defineCacheRoutes(<<<'PHP'
<?php
throw new RuntimeException('route cache failed');
PHP);

            $this->fail('Expected route caching to fail.');
        } catch (Throwable) {
            $this->assertCount(1, $this->testbenchRouteFiles);

            $routeFile = $this->testbenchRouteFiles[0];

            $this->assertFileExists($routeFile);

            $this->callBeforeApplicationDestroyedCallbacks();

            $this->assertFileDoesNotExist($routeFile);
        }
    }

    public function testTestbenchRouteFilePathIsUniquePerCall(): void
    {
        $firstRouteFile = $this->testbenchRouteFilePath($this->app->basePath());
        $secondRouteFile = $this->testbenchRouteFilePath($this->app->basePath());

        $this->assertNotSame($firstRouteFile, $secondRouteFile);
        $this->assertStringStartsWith($this->app->basePath('routes/testbench-'), $firstRouteFile);
        $this->assertStringEndsWith('.php', $firstRouteFile);
    }

    public function testCacheFileExistsAfterDefineCacheRoutes(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php
use Hypervel\Support\Facades\Route;
Route::get('/cache-exists', fn () => 'ok');
PHP);

        $this->assertFileExists($this->app->getCachedRoutesPath());
    }

    public function testSetUpApplicationRoutesSkipsWhenRoutesCached(): void
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
}
