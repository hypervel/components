<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\Features\TestingFeature;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\refresh_router_lookups;

trait HandlesRoutes
{
    use InteractsWithPHPUnit;
    use InteractsWithTestCase;

    /**
     * Whether cached routes have been set up for this test.
     */
    protected bool $defineCacheRoutesHasRun = false;

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
    protected function defineStashRoutes(string $routes): void
    {
        $this->defineCacheRoutes($routes, false);
    }

    /**
     * Define cached routes for the application.
     *
     * Writes the given route definitions to a temporary file, registers them,
     * compiles to a cache file, then reloads the application so it boots with
     * CompiledRouteCollection. Cleans up both files on teardown.
     */
    protected function defineCacheRoutes(string $routes, bool $cached = true): void
    {
        $files = new Filesystem();

        $basePath = $this->app->basePath();

        // Use a random suffix instead of time() to guarantee a unique path per
        // invocation. ReflectionClosure caches tokenized source by file path,
        // so reusing the same path within a process causes stale closure bodies
        // to be serialized into the route cache.
        $routeFile = join_paths($basePath, 'routes', 'testbench-' . bin2hex(random_bytes(8)) . '.php');

        // Ensure the routes directory exists
        $files->ensureDirectoryExists(dirname($routeFile));

        // Write route definitions to temp file
        $files->put($routeFile, $routes);

        if ($cached === true) {
            // Reset the router to a fresh collection so only this invocation's
            // routes are compiled. Without this, routes from a previous
            // defineCacheRoutes() call would accumulate on the router.
            /** @var Router $router */
            $router = $this->app['router'];
            $router->setRoutes(new RouteCollection());

            require $routeFile;

            /** @var RouteCollection $routeCollection */
            $routeCollection = $router->getRoutes();

            $routeCollection->refreshNameLookups();
            $routeCollection->refreshActionLookups();

            // Serialize each route for caching
            foreach ($routeCollection as $route) {
                $route->prepareForSerialization();
            }

            // Compile routes in memory before reloading so the data survives the
            // reload. Writing the cache file is deferred until after reload to avoid
            // a previous invocation's beforeApplicationDestroyed cleanup deleting
            // the file before we can require it.
            $stub = $files->get(
                join_paths(dirname(__DIR__, 3), 'foundation', 'src', 'Console', 'stubs', 'routes.stub')
            );

            $cacheContent = str_replace(
                '{{routes}}',
                var_export($routeCollection->compile(), true),
                $stub
            );
        }

        // Reload the app — any cleanup callbacks from a previous
        // defineCacheRoutes() call will fire here safely, since we
        // haven't written this invocation's cache file yet.
        $this->reloadApplication();

        if ($cached === true) {
            // Write and load the cache file on the NEW app. The workbench has
            // no RouteServiceProvider to load it automatically. The cache file
            // calls app('router')->setCompiledRoutes(...), installing the
            // CompiledRouteCollection.
            $cachePath = $this->app->getCachedRoutesPath();
            $files->ensureDirectoryExists(dirname($cachePath));
            $files->put($cachePath, $cacheContent);
            require $cachePath;
        }

        // Register cleanup on the NEW app (after reload), so the cache file
        // persists through the reload but is cleaned up when the test finishes
        $this->beforeApplicationDestroyed(function () use ($files, $routeFile) {
            $files->delete($routeFile);

            // Use the current app's cache path (may differ after reload)
            if ($this->app) {
                $files->delete($this->app->getCachedRoutesPath());
            }
        });

        $this->defineCacheRoutesHasRun = true;
    }
}
