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

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\refresh_router_lookups;
use function Hypervel\Testbench\remote;

trait HandlesRoutes
{
    use InteractsWithPHPUnit;
    use InteractsWithTestCase;

    /**
     * Whether cached routes have been loaded for this test.
     */
    protected bool $requireApplicationCachedRoutesHasRun = false;

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

        /** @var Router $router */
        $router = $app['router'];

        TestingFeature::run(
            testCase: $this,
            default: function () use ($router) {
                $this->defineRoutes($router);

                $router->middleware('web')
                    ->group(fn ($router) => $this->defineWebRoutes($router));
            },
            attribute: fn () => $this->parseTestMethodAttributes($this->app, DefineRoute::class),
            pest: function () use ($router) {
                $this->defineRoutesUsingPest($router); /* @phpstan-ignore method.notFound */

                $router->middleware('web')
                    ->group(fn ($router) => $this->defineWebRoutesUsingPest($router)); /* @phpstan-ignore method.notFound */
            },
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
        if ($route instanceof Closure) {
            $cached = false;
            /** @var string $serializeRoute */
            $serializeRoute = serialize(SerializableClosure::unsigned($route));
            $stub = $files->get(join_paths(__DIR__, 'Fixtures', 'routes.stub'));
            $route = str_replace('{{routes}}', var_export($serializeRoute, true), $stub);
        }

        $routeFile = $this->testbenchRouteFilePath($basePath);
        $this->testbenchRouteFiles[] = $routeFile;

        $files->put($routeFile, $route);
        $this->registerTestbenchRouteCleanup($files);

        if ($cached === true) {
            remote('route:cache')->mustRun();

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

                $this->testbenchRouteCleanupRegistered = false;
                $this->registerTestbenchRouteCleanup($files);
            }
        }

        $this->requireApplicationCachedRoutes($files, $cached);
    }

    /**
     * Require application cached routes.
     *
     * @internal
     */
    protected function requireApplicationCachedRoutes(Filesystem $files, bool $cached): void
    {
        if ($this->requireApplicationCachedRoutesHasRun === true) {
            return;
        }

        $this->afterApplicationCreated(function () use ($cached): void {
            $app = $this->app;

            if ($app instanceof HypervelApplication) {
                if ($cached === true) {
                    require $app->getCachedRoutesPath();
                } else {
                    (new SyncTestbenchCachedRoutes)->bootstrap($app);
                }
            }
        });

        $this->requireApplicationCachedRoutesHasRun = true;
    }

    /**
     * Register cleanup for route files and route cache written by this test.
     */
    protected function registerTestbenchRouteCleanup(Filesystem $files): void
    {
        if ($this->testbenchRouteCleanupRegistered === true) {
            return;
        }

        $this->beforeApplicationDestroyed(function () use ($files): void {
            if ($this->reloadingApplicationForCachedRoutes === true) {
                return;
            }

            if ($this->app instanceof HypervelApplication) {
                // Use the dynamic cache path — parallel workers suffix it with _test_{token},
                // so hardcoding routes-v7.php would miss the actual file and leak stale caches.
                $files->delete($this->app->getCachedRoutesPath());
            }

            $files->delete($this->testbenchRouteFiles);
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
