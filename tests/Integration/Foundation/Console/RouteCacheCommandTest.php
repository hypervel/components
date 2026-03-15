<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Container\Container;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\CompiledRouteCollection;
use ReflectionClass;
use Workbench\App\Exceptions\ExceptionHandler;

/**
 * @internal
 * @coversNothing
 */
class RouteCacheCommandTest extends \Hypervel\Testbench\TestCase
{
    protected Filesystem $files;

    protected string $providersPath;

    protected string $sourceWorkbenchAppPath;

    protected ?string $originalProvidersContents = null;

    /**
     * @var array<int, string>
     */
    protected array $providerFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->providersPath = $this->app->getBootstrapProvidersPath();
        $this->sourceWorkbenchAppPath = dirname((new ReflectionClass(ExceptionHandler::class))->getFileName(), 2);
        $this->originalProvidersContents = file_exists($this->providersPath)
            ? file_get_contents($this->providersPath)
            : null;

        $this->files->ensureDirectoryExists($this->sourceWorkbenchAppPath . '/Providers');
    }

    protected function tearDown(): void
    {
        foreach ($this->providerFiles as $providerFile) {
            $this->files->delete($providerFile);
        }

        if ($this->originalProvidersContents !== null) {
            $this->files->put($this->providersPath, $this->originalProvidersContents);
        }

        $this->files->delete($this->app->getCachedRoutesPath());

        parent::tearDown();
    }

    public function testRouteCacheSucceedsWithSourceRoutes()
    {
        $this->registerRouteProvider(
            'FooRouteCacheServiceProvider',
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
        $this->registerRouteProvider(
            'LoadableRouteCacheServiceProvider',
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
        $this->registerRouteProvider(
            'NamedRouteCacheServiceProvider',
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
        $this->registerRouteProvider(
            'ComplexRouteCacheServiceProvider',
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
        $this->registerRouteProvider(
            'ClearRouteCacheServiceProvider',
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
        $this->registerRouteProvider(
            'ContainerSafeRouteCacheServiceProvider',
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
        $this->registerRouteProvider(
            'AlphaRouteCacheServiceProvider',
            <<<'PHP'
            Route::get('/alpha', fn () => 'alpha')->name('source.route');
            PHP
        );

        $this->artisan('route:cache')->assertSuccessful();

        $this->registerRouteProvider(
            'BetaRouteCacheServiceProvider',
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

    protected function registerRouteProvider(string $className, string $routeDefinitions): void
    {
        $providerClass = "Workbench\\App\\Providers\\{$className}";
        $providerPath = $this->sourceWorkbenchAppPath . "/Providers/{$className}.php";

        $this->files->put(
            $providerPath,
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Workbench\\App\\Providers;

            use Hypervel\\Support\\Facades\\Route;
            use Hypervel\\Support\\ServiceProvider;

            class {$className} extends ServiceProvider
            {
                public function boot(): void
                {
                    {$routeDefinitions}
                }
            }
            PHP
        );

        $this->providerFiles[] = $providerPath;

        $this->setBootstrapProviders([$providerClass]);
    }

    /**
     * @param array<int, string> $providers
     */
    protected function setBootstrapProviders(array $providers): void
    {
        $this->files->put(
            $this->providersPath,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($providers, true) . ";\n"
        );
    }
}
