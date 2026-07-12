<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use RuntimeException;

use function Hypervel\Testbench\testbench_path;

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

    /** @var array<int, string> */
    protected array $cacheFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->assertCleanTestbenchRouteSources();
    }

    protected function tearDown(): void
    {
        $actions = [];

        foreach ($this->routeFiles as $routeFile) {
            $actions[] = function () use ($routeFile): void {
                if ($this->files->isFile($routeFile) && ! $this->files->delete($routeFile)) {
                    throw new RuntimeException("Unable to delete owned route cache test file [{$routeFile}].");
                }
            };
        }

        foreach ($this->cacheFiles as $cacheFile) {
            $actions[] = function () use ($cacheFile): void {
                if ($this->files->isFile($cacheFile) && ! $this->files->delete($cacheFile)) {
                    throw new RuntimeException("Unable to delete owned route cache test file [{$cacheFile}].");
                }
            };
        }

        $cachePath = $this->app->getCachedRoutesPath();
        $actions[] = function () use ($cachePath): void {
            if ($this->files->isFile($cachePath) && ! $this->files->delete($cachePath)) {
                throw new RuntimeException("Unable to delete owned route cache file [{$cachePath}].");
            }
        };
        $actions[] = fn () => parent::tearDown();

        CleanupActions::run(...$actions);
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

    public function testRouteCacheSubprocessUsesTheParentsResolvedCachePath(): void
    {
        $previousCachePath = $_SERVER['APP_ROUTES_CACHE'] ?? null;
        $hadCachePath = array_key_exists('APP_ROUTES_CACHE', $_SERVER);
        $defaultCachePath = $this->app->bootstrapPath('cache/routes-v7.php');
        $alternateCachePath = $this->app->bootstrapPath('cache/routes-alternate.php');
        $this->cacheFiles[] = $defaultCachePath;
        $this->cacheFiles[] = $alternateCachePath;
        $this->files->put(
            $defaultCachePath,
            '<?php throw new RuntimeException("The route subprocess loaded the stale default cache.");',
        );
        $_SERVER['APP_ROUTES_CACHE'] = $alternateCachePath;

        try {
            $this->defineTestbenchRoutes(
                <<<'PHP'
                Route::get('/alternate', fn () => 'alternate')->name('alternate.index');
                PHP
            );

            $this->artisan('route:cache')->assertSuccessful();

            $this->assertFileExists($alternateCachePath);
        } finally {
            if ($hadCachePath) {
                $_SERVER['APP_ROUTES_CACHE'] = $previousCachePath;
            } else {
                unset($_SERVER['APP_ROUTES_CACHE']);
            }
        }
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

    /**
     * Assert route-producing skeleton state is pristine for this worker.
     */
    protected function assertCleanTestbenchRouteSources(): void
    {
        foreach (['bootstrap/app.php', 'bootstrap/providers.php'] as $relativePath) {
            $runtimePath = $this->app->basePath($relativePath);
            $pristinePath = testbench_path('hypervel/' . $relativePath);

            $this->assertSame(
                $this->files->get($pristinePath),
                $this->files->get($runtimePath),
                "Testbench runtime file [{$runtimePath}] differs from its pristine skeleton source.",
            );
        }

        $routeFiles = $this->files->glob($this->app->basePath('routes/*.php'));

        $this->assertSame(
            [],
            $routeFiles,
            'Unexpected Testbench runtime route files found: ' . implode(', ', $routeFiles),
        );
    }
}
