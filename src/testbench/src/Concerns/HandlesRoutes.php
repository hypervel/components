<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application as HypervelApplication;
use Hypervel\Routing\Router;
use Hypervel\Support\Env;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Testbench\Features\TestingFeature;
use Hypervel\Testbench\Foundation\Bootstrap\SyncTestbenchCachedRoutes;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\refresh_router_lookups;
use function Hypervel\Testbench\remote;

trait HandlesRoutes
{
    use InteractsWithPHPUnit;
    use InteractsWithTestCase;

    /**
     * Whether Testbench routes have been synchronized for this test.
     */
    protected bool $syncTestbenchRoutesHasRun = false;

    /**
     * Whether route file cleanup has been registered for this test.
     */
    protected bool $testbenchRouteCleanupRegistered = false;

    /**
     * Whether defineCacheRoutes() is reloading the application to load cached routes.
     */
    protected bool $reloadingApplicationForCachedRoutes = false;

    /**
     * Route files written by this test instance.
     *
     * @var array<int, string>
     */
    protected array $testbenchRouteFiles = [];

    /**
     * Setup application routes.
     */
    protected function setUpApplicationRoutes(ApplicationContract $app): void
    {
        if ($app->routesAreCached()) {
            return;
        }

        $router = $app->make(Router::class);

        TestingFeature::run(
            testCase: $this,
            default: function () use ($router) {
                $this->defineRoutes($router);

                $router->middleware('web')
                    ->group(fn ($router) => $this->defineWebRoutes($router));
            },
            attribute: fn () => $this->parseTestMethodAttributes($app, DefineRoute::class),
        );

        refresh_router_lookups($router);
    }

    /**
     * Define routes setup.
     */
    protected function defineRoutes(Router $router): void
    {
        // Define routes.
    }

    /**
     * Define web routes setup.
     */
    protected function defineWebRoutes(Router $router): void
    {
        // Define web routes.
    }

    /**
     * Define stash routes setup.
     */
    protected function defineStashRoutes(Closure|string $route): void
    {
        $this->defineCacheRoutes($route, false);
    }

    /**
     * Define cache routes setup.
     */
    protected function defineCacheRoutes(Closure|string $route, bool $cached = true): void
    {
        $this->configureParallelCachePaths();

        static::usesTestingFeature($attribute = new UsesVendor, Attribute::TARGET_METHOD);

        if (
            $this->app instanceof HypervelApplication
            && property_exists($this, 'setUpHasRun') /* @phpstan-ignore function.alreadyNarrowedType */
            && $this->setUpHasRun === true
        ) {
            $attribute->beforeEach($this->app);
        }

        $files = new Filesystem;

        $basePath = static::applicationBasePath();

        if ($this->app instanceof HypervelApplication) {
            $cachedRoutesPath = $this->app->getCachedRoutesPath();
        } else {
            $configuredCachedRoutesPath = Env::get('APP_ROUTES_CACHE');

            $cachedRoutesPath = $configuredCachedRoutesPath === null
                ? join_paths($basePath, 'bootstrap', 'cache', 'routes-v7.php')
                : (Str::startsWith($configuredCachedRoutesPath, ['/', '\\'])
                    ? $configuredCachedRoutesPath
                    : join_paths($basePath, $configuredCachedRoutesPath));
        }

        if ($route instanceof Closure) {
            $cached = false;
            /** @var string $serializeRoute */
            $serializeRoute = serialize(SerializableClosure::unsigned($route));
            $stub = $files->get(join_paths(__DIR__, 'Fixtures', 'routes.stub'));
            $route = str_replace('{{routes}}', var_export($serializeRoute, true), $stub);
        }

        $routeFile = $this->testbenchRouteFilePath($basePath);
        $this->testbenchRouteFiles[] = $routeFile;

        $files->replace($routeFile, $route);
        $this->registerTestbenchRouteCleanup($files, $cachedRoutesPath);

        if ($cached === true) {
            remote('route:cache')->mustRun();

            if (! $files->exists($cachedRoutesPath)) {
                throw new RuntimeException("Route cache file was not created at [{$cachedRoutesPath}].");
            }
        }

        if ($this->app instanceof HypervelApplication) {
            $this->reloadingApplicationForCachedRoutes = true;

            try {
                $this->reloadApplication();
            } finally {
                $this->reloadingApplicationForCachedRoutes = false;
                $this->syncTestbenchRoutesHasRun = false;

                $this->testbenchRouteCleanupRegistered = false;
                $this->registerTestbenchRouteCleanup($files, $cachedRoutesPath);
            }
        }

        if ($cached === false) {
            $this->syncTestbenchRoutes();
        }
    }

    /**
     * Synchronize Testbench routes.
     *
     * @internal
     */
    protected function syncTestbenchRoutes(): void
    {
        if ($this->syncTestbenchRoutesHasRun === true) {
            return;
        }

        $this->afterApplicationCreated(function (): void {
            /** @var ApplicationContract $app */
            $app = $this->app;

            (new SyncTestbenchCachedRoutes)->bootstrap($app);
        });

        $this->syncTestbenchRoutesHasRun = true;
    }

    /**
     * Register cleanup for route files and route cache written by this test.
     */
    protected function registerTestbenchRouteCleanup(Filesystem $files, string $cachedRoutesPath): void
    {
        if ($this->testbenchRouteCleanupRegistered === true) {
            return;
        }

        $this->beforeApplicationDestroyed(function () use ($files, $cachedRoutesPath): void {
            if ($this->reloadingApplicationForCachedRoutes === true) {
                return;
            }

            $failure = null;

            try {
                if ($files->exists($cachedRoutesPath) && ! $files->delete($cachedRoutesPath)) {
                    throw new RuntimeException("Unable to remove route cache [{$cachedRoutesPath}].");
                }
            } catch (Throwable $throwable) {
                $failure = $throwable;
            }

            try {
                $routeFiles = array_values(array_filter(
                    $this->testbenchRouteFiles,
                    static fn (string $routeFile): bool => $files->exists($routeFile),
                ));

                if ($routeFiles !== [] && ! $files->delete($routeFiles)) {
                    $survivors = array_values(array_filter(
                        $routeFiles,
                        static fn (string $routeFile): bool => $files->exists($routeFile),
                    ));

                    throw new RuntimeException(sprintf(
                        'Unable to remove Testbench route files [%s].',
                        implode(', ', $survivors),
                    ));
                }
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }

            if ($failure !== null) {
                throw $failure;
            }
        });

        $this->testbenchRouteCleanupRegistered = true;
    }

    /**
     * Get a route file path owned by this test instance.
     */
    protected function testbenchRouteFilePath(string $basePath): string
    {
        $token = $this->paraTestWorkerToken() ?? 'default';

        return join_paths(
            $basePath,
            'routes',
            sprintf('testbench-%s-%s-%s.php', $token, getmypid(), hrtime(true))
        );
    }
}
