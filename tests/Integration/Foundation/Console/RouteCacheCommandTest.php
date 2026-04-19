<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\CompiledRouteCollection;

class RouteCacheCommandTest extends \Hypervel\Testbench\TestCase
{
    protected Filesystem $files;

    /**
     * Tracks the routes/testbench-*.php files written during each test so
     * tearDown can clean them up regardless of how the test exits.
     *
     * @var array<int, string>
     */
    protected array $routeFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
    }

    protected function tearDown(): void
    {
        foreach ($this->routeFiles as $routeFile) {
            $this->files->delete($routeFile);
        }

        $this->files->delete($this->app->getCachedRoutesPath());

        parent::tearDown();
    }

    public function testRouteCacheSucceedsWithSourceRoutes()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/foo', fn () => 'foo')->name('foo.index');
            PHP
        );

        $this->artisan('route:cache')
            ->assertSuccessful()
            ->expectsOutputToContain('Routes cached successfully');

        $this->assertFileExists($this->app->getCachedRoutesPath());
    }

    public function testRouteCacheFailsWithNoRoutes()
    {
        $this->artisan('route:cache')
            ->expectsOutputToContain("doesn't have any routes")
            ->assertExitCode(1);
    }

    public function testCachedRoutesAreLoadable()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/foo', fn () => 'foo')->name('foo.index');
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();

        require $this->app->getCachedRoutesPath();

        $this->assertInstanceOf(CompiledRouteCollection::class, $this->app['router']->getRoutes());
    }

    public function testNamedRoutesSurviveCache()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/users', fn () => 'users')->name('users.index');
            Route::get('/posts', fn () => 'posts')->name('posts.index');
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();

        require $this->app->getCachedRoutesPath();

        $routes = $this->app['router']->getRoutes();

        $this->assertSame('users', $routes->getByName('users.index')?->uri());
        $this->assertSame('posts', $routes->getByName('posts.index')?->uri());
    }

    public function testRoutesWithMiddlewareDomainPrefixAndMultipleMethodsSurviveCache()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::middleware('auth')->group(function (): void {
                Route::domain('api.example.com')->prefix('api/v1')->group(function (): void {
                    Route::match(['GET', 'POST'], '/users', fn () => 'users')->name('api.users');
                });
            });
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();

        require $this->app->getCachedRoutesPath();

        $route = $this->app['router']->getRoutes()->getByName('api.users');

        $this->assertNotNull($route);
        $this->assertSame('api.example.com', $route->getDomain());
        $this->assertSame('api/v1/users', $route->uri());
        $this->assertContains('auth', $route->middleware());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('POST', $route->methods());
    }

    public function testRouteClearRemovesCacheFile()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/foo', fn () => 'foo')->name('foo.index');
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();
        $this->assertFileExists($this->app->getCachedRoutesPath());

        $this->artisan('route:clear')->assertSuccessful();

        $this->assertFileDoesNotExist($this->app->getCachedRoutesPath());
    }

    public function testRouteCacheDoesNotOverwriteGlobalContainerInstance()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/foo', fn () => 'foo')->name('foo.index');
            PHP
        );

        $originalInstance = Container::getInstance();

        $this->artisan('route:cache')->assertSuccessful();

        $this->assertSame($originalInstance, Container::getInstance());
    }

    public function testRouteCacheRebuildsFromSourceWhenApplicationBootedWithExistingCachedRoutes()
    {
        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/alpha', fn () => 'alpha')->name('source.route');
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();

        // Remove the Alpha route file and define Beta so the second route:cache
        // run has ONLY /beta in its source. Both files would produce a route
        // name collision ('source.route') if loaded together.
        $this->files->delete(array_pop($this->routeFiles));

        $this->defineTestbenchRoutes(
            <<<'PHP'
            Route::get('/beta', fn () => 'beta')->name('source.route');
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();

        require $this->app->getCachedRoutesPath();

        $route = $this->app['router']->getRoutes()->getByName('source.route');

        $this->assertNotNull($route);
        $this->assertSame('beta', $route->uri());
    }

    /**
     * Write a testbench route file into the cloned skeleton's routes dir.
     *
     * The skeleton's bootstrap/app.php runs SyncTestbenchCachedRoutes on the
     * booted hook, which globs routes/testbench-*.php and requires each
     * file — so routes written here are picked up by any process booting
     * the clone, including the subprocess that route:cache spawns.
     *
     * Writing route files (not provider classes) avoids runtime class
     * generation, which can't work across the subprocess boundary since
     * the subprocess uses its own vendor/autoload.php.
     */
    protected function defineTestbenchRoutes(string $routeDefinitions): void
    {
        $routePath = $this->app->basePath('routes/testbench-' . uniqid('', true) . '.php');

        $this->files->put(
            $routePath,
            <<<PHP
            <?php

            declare(strict_types=1);

            use Hypervel\\Support\\Facades\\Route;

            {$routeDefinitions}
            PHP
        );

        $this->routeFiles[] = $routePath;
    }
}
