<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Attribute;
use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application as HypervelApplication;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\DefineRoute;
use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Testbench\Features\TestingFeature;
use Hypervel\Testbench\Foundation\Bootstrap\SyncTestbenchCachedRoutes;
use Laravel\SerializableClosure\SerializableClosure;

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
        static::usesTestingFeature($attribute = new UsesVendor(), Attribute::TARGET_METHOD);

        if (
            $this->app instanceof HypervelApplication
            && property_exists($this, 'setUpHasRun') /* @phpstan-ignore function.alreadyNarrowedType */
            && $this->setUpHasRun === true
        ) {
            $attribute->beforeEach($this->app);
        }

        $files = new Filesystem();

        $time = time();

        $basePath = static::applicationBasePath();
        if ($route instanceof Closure) {
            $cached = false;
            /** @var string $serializeRoute */
            $serializeRoute = serialize(SerializableClosure::unsigned($route));
            $stub = $files->get(join_paths(__DIR__, 'Fixtures', 'routes.stub'));
            $route = str_replace('{{routes}}', var_export($serializeRoute, true), $stub);
        }

        $files->put(
            join_paths($basePath, 'routes', "testbench-{$time}.php"),
            $route
        );

        if ($cached === true) {
            remote('route:cache')->mustRun();

            \assert($this->app instanceof HypervelApplication);
            \assert($files->exists($this->app->getCachedRoutesPath()) === true);
        }

        if ($this->app instanceof HypervelApplication) {
            $this->reloadApplication();
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
                    (new SyncTestbenchCachedRoutes())->bootstrap($app);
                }
            }
        });

        $this->beforeApplicationDestroyed(function () use ($files): void {
            if ($this->app instanceof HypervelApplication) {
                // Use the dynamic cache path — parallel workers suffix it with _test_{token},
                // so hardcoding routes-v7.php would miss the actual file and leak stale caches.
                $files->delete(
                    $this->app->getCachedRoutesPath(),
                    ...$files->glob($this->app->basePath(join_paths('routes', 'testbench-*.php')))
                );
            }

            usleep(1_000_000);
        });

        $this->requireApplicationCachedRoutesHasRun = true;
    }
}
