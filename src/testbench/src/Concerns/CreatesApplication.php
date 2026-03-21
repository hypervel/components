<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Closure;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\BootProviders;
use Hypervel\Foundation\Bootstrap\HandleExceptions as FoundationHandleExceptions;
use Hypervel\Foundation\Bootstrap\LoadConfiguration as FoundationLoadConfiguration;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\Testing\Concerns\InteractsWithParallelDatabase;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Routing\Router;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\RequiresEnv;
use Hypervel\Testbench\Attributes\RequiresHypervel;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Hypervel\Testbench\Attributes\UsesFrameworkConfiguration;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\Bootstrap\HandleExceptions as TestbenchHandleExceptions;
use Hypervel\Testbench\Bootstrap\LoadConfiguration as TestbenchLoadConfiguration;
use Hypervel\Testbench\Bootstrap\LoadConfigurationWithWorkbench;
use Hypervel\Testbench\Bootstrap\RegisterProviders as TestbenchRegisterProviders;
use Hypervel\Testbench\Features\TestingFeature;
use Hypervel\Testbench\Foundation\Bootstrap\SyncDatabaseEnvironmentVariables;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\PackageManifest;
use Hypervel\Testbench\Foundation\UndefinedValue;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

use function Hypervel\Testbench\after_resolving;
use function Hypervel\Testbench\default_skeleton_path;
use function Hypervel\Testbench\refresh_router_lookups;

/**
 * Creates and bootstraps the application for testbench tests.
 *
 * Mirrors Orchestral Testbench's CreatesApplication: bootstrappers are
 * called individually so defineEnvironment() can modify config between
 * RegisterProviders and BootProviders. The testbench Console Kernel
 * returns an empty bootstrapper list, so the final bootstrap() call
 * only sets hasBeenBootstrapped and loads commands.
 */
trait CreatesApplication
{
    use InteractsWithParallelDatabase;
    use InteractsWithWorkbench;
    use WithHypervelBootstrapFile;

    /**
     * Get the base path for the application.
     */
    public static function applicationBasePath(): string
    {
        return static::applicationBasePathUsingWorkbench() ?? (default_skeleton_path() ?: '');
    }

    /**
     * Ignore package discovery from.
     *
     * @return array<int, string>
     */
    public function ignorePackageDiscoveriesFrom(): array
    {
        return $this->ignorePackageDiscoveriesFromUsingWorkbench() ?? ['*'];
    }

    /**
     * Resolve the application's base path.
     */
    protected function getApplicationBasePath(): string
    {
        return static::applicationBasePath();
    }

    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return $this->getPackageProvidersUsingWorkbench($app) ?? [];
    }

    /**
     * Get package bootstrappers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageBootstrappers(ApplicationContract $app): array
    {
        return $this->getPackageBootstrappersUsingWorkbench($app) ?? [];
    }

    /**
     * Get application providers.
     *
     * Override in test classes to filter the default provider list before
     * registration. For example, to remove SessionServiceProvider.
     *
     * @return array<int, class-string>
     */
    protected function getApplicationProviders(ApplicationContract $app): array
    {
        return $app->make('config')->get('app.providers', []);
    }

    /**
     * Get the application timezone.
     */
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return $app->make('config')->get('app.timezone');
    }

    /**
     * Override application providers.
     *
     * Return a map of provider class names to replacements. Set a provider
     * to `false` to remove it entirely, or to another class name to replace it.
     *
     * @return array<class-string, class-string|false>
     */
    protected function overrideApplicationProviders(ApplicationContract $app): array
    {
        return [];
    }

    /**
     * Get package aliases.
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [];
    }

    /**
     * Override application bindings.
     *
     * @return array<class-string|string, class-string|string>
     */
    protected function overrideApplicationBindings(ApplicationContract $app): array
    {
        return [];
    }

    /**
     * Define environment setup.
     *
     * Override in subclasses to modify config before providers boot.
     * This is where test classes set database drivers, cache stores, etc.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        // Override in subclass.
    }

    /**
     * Create the application instance.
     *
     * Bootstraps the application manually step-by-step (like Orchestral
     * Testbench's createApplication), rather than via kernel->bootstrap(),
     * so defineEnvironment() can be called between RegisterProviders and
     * BootProviders.
     */
    public function createApplication(): ApplicationContract
    {
        $this->configureParallelCachePaths();

        $app = $this->resolveApplication();

        $this->resolveApplicationBindings($app);
        $this->resolveApplicationExceptionHandler($app);
        $this->resolveApplicationEnvironmentVariables($app);
        $this->resolveApplicationConfiguration($app);

        // Must run AFTER resolveApplicationConfiguration() because LoadConfiguration's
        // parent::bootstrap() calls detectEnvironment() with config('app.env'), which
        // would overwrite the 'testing' environment. By running after, our
        // detectEnvironment('testing') takes precedence.
        $this->resolveApplicationCore($app);

        $this->resolveApplicationHttpKernel($app);
        $this->resolveApplicationHttpMiddlewares($app);
        $this->resolveApplicationConsoleKernel($app);
        $this->resolveApplicationBootstrappers($app);
        $this->refreshApplicationRouteNameLookups($app);

        return $app;
    }

    protected function resolveApplication(): ApplicationContract
    {
        static::$cacheApplicationBootstrapFile ??= $this->getApplicationBootstrapFile('app.php');

        if (is_string(static::$cacheApplicationBootstrapFile)) {
            $APP_BASE_PATH = $this->getApplicationBasePath();

            /** @var ApplicationContract $app */
            $app = require static::$cacheApplicationBootstrapFile;

            return $app;
        }

        $app = new Application($this->getApplicationBasePath());

        return $app;
    }

    /**
     * Resolve application bindings.
     */
    protected function resolveApplicationBindings(ApplicationContract $app): void
    {
        foreach ($this->overrideApplicationBindings($app) as $original => $replacement) {
            $app->bind($original, $replacement);
        }
    }

    /**
     * Resolve application HTTP exception handler.
     */
    protected function resolveApplicationExceptionHandler(ApplicationContract $app): void
    {
        $app->singleton(ExceptionHandlerContract::class, $this->applicationExceptionHandlerUsingWorkbench($app));
    }

    /**
     * Resolve application core environment.
     */
    protected function resolveApplicationCore(ApplicationContract $app): void
    {
        if ($this->isRunningTestCase()) {
            $app->detectEnvironment(static fn () => 'testing');
        }
    }

    /**
     * Resolve application environment variables.
     */
    protected function resolveApplicationEnvironmentVariables(ApplicationContract $app): void
    {
        if (property_exists($this, 'loadEnvironmentVariables') && $this->loadEnvironmentVariables !== true) { /* @phpstan-ignore function.alreadyNarrowedType */
            if ($this instanceof PHPUnitTestCase && method_exists($this, 'beforeApplicationDestroyed')) { /* @phpstan-ignore function.alreadyNarrowedType */
                $this->beforeApplicationDestroyed($this->maskInheritedApplicationEnvironment());
            }
        }

        if (property_exists($this, 'loadEnvironmentVariables') && $this->loadEnvironmentVariables === true) { /* @phpstan-ignore function.alreadyNarrowedType */
            $app->make(LoadEnvironmentVariables::class)->bootstrap($app);
        }

        $attributeCallbacks = TestingFeature::run(
            testCase: $this,
            attribute: fn () => $this->parseTestMethodAttributes($app, WithEnv::class),
        )->get('attribute');

        TestingFeature::run(
            testCase: $this,
            attribute: function () use ($app) {
                $this->parseTestMethodAttributes($app, RequiresEnv::class);
                $this->parseTestMethodAttributes($app, RequiresHypervel::class);
            },
        );

        if ($this instanceof PHPUnitTestCase && method_exists($this, 'beforeApplicationDestroyed')) { /* @phpstan-ignore function.alreadyNarrowedType */
            $this->beforeApplicationDestroyed(static function () use ($attributeCallbacks) {
                $attributeCallbacks->handle();
            });
        }
    }

    /**
     * Mask inherited framework APP_ENV for Testbench applications that opt out of loading env files.
     *
     * The framework suite forces APP_ENV=testing globally via phpunit.xml.dist.
     * Tests that disable environment loading should instead use the configuration
     * defaults unless they explicitly provided APP_ENV or package-tester mode is enabled.
     *
     * @return Closure(): void
     */
    protected function maskInheritedApplicationEnvironment(): Closure
    {
        if (Env::has('TESTBENCH_PACKAGE_TESTER') || $this->hasConfiguredApplicationEnvironmentVariable('APP_ENV')) {
            return static fn (): null => null;
        }

        $originalServerValue = $_SERVER['APP_ENV'] ?? new UndefinedValue();
        $originalEnvironmentValue = $_ENV['APP_ENV'] ?? new UndefinedValue();
        $originalProcessValue = getenv('APP_ENV');

        unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);

        putenv('APP_ENV');
        Env::flushRepository();

        return static function () use ($originalServerValue, $originalEnvironmentValue, $originalProcessValue): void {
            if ($originalServerValue instanceof UndefinedValue) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $originalServerValue;
            }

            if ($originalEnvironmentValue instanceof UndefinedValue) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $originalEnvironmentValue;
            }

            if ($originalProcessValue === false) {
                putenv('APP_ENV');
            } else {
                putenv("APP_ENV={$originalProcessValue}");
            }

            Env::flushRepository();
        };
    }

    /**
     * Determine if the current test explicitly configured an environment variable.
     */
    protected function hasConfiguredApplicationEnvironmentVariable(string $key): bool
    {
        return $this->resolvePhpUnitAttributes()
            ->flatten()
            ->contains(
                static fn ($instance) => $instance instanceof WithEnv && $instance->key === $key
            );
    }

    /**
     * Resolve application HTTP kernel implementation.
     */
    protected function resolveApplicationHttpKernel(ApplicationContract $app): void
    {
        $app->singleton(HttpKernelContract::class, $this->applicationHttpKernelUsingWorkbench($app));
    }

    /**
     * Resolve application HTTP default middlewares.
     */
    protected function resolveApplicationHttpMiddlewares(ApplicationContract $app): void
    {
        $app->afterResolving(HttpKernelContract::class, function ($kernel): void {
            $middleware = (new \Hypervel\Foundation\Configuration\Middleware())
                ->redirectGuestsTo(fn () => route('login'));

            $kernel->setGlobalMiddleware($middleware->getGlobalMiddleware());
            $kernel->setMiddlewareGroups($middleware->getMiddlewareGroups());
            $kernel->setMiddlewareAliases($middleware->getMiddlewareAliases());

            if ($priorities = $middleware->getMiddlewarePriority()) {
                $kernel->setMiddlewarePriority($priorities);
            }

            if ($priorityAppends = $middleware->getMiddlewarePriorityAppends()) {
                foreach ($priorityAppends as $newMiddleware => $after) {
                    $kernel->addToMiddlewarePriorityAfter($after, $newMiddleware);
                }
            }

            if ($priorityPrepends = $middleware->getMiddlewarePriorityPrepends()) {
                foreach ($priorityPrepends as $newMiddleware => $before) {
                    $kernel->addToMiddlewarePriorityBefore($before, $newMiddleware);
                }
            }
        });
    }

    /**
     * Resolve application console kernel implementation.
     */
    protected function resolveApplicationConsoleKernel(ApplicationContract $app): void
    {
        // Use the Testbench console kernel so the final bootstrap() call only
        // marks the app bootstrapped and loads commands.
        $app->singleton(KernelContract::class, $this->applicationConsoleKernelUsingWorkbench($app));
    }

    /**
     * Load configuration and register package providers/aliases.
     *
     * Equivalent to Orchestral's resolveApplicationConfiguration(): loads
     * config files, then sets app.providers and app.aliases in config
     * BEFORE RegisterProviders reads them.
     */
    protected function resolveApplicationConfiguration(ApplicationContract $app): void
    {
        $loadConfiguration = static::usesTestingConcern() && ! static::usesTestingConcern(WithWorkbench::class)
            ? TestbenchLoadConfiguration::class
            : LoadConfigurationWithWorkbench::class;

        $app->bind(FoundationLoadConfiguration::class, $loadConfiguration);

        TestingFeature::run(
            testCase: $this,
            attribute: function () use ($app) {
                $this->parseTestMethodAttributes($app, ResolvesHypervel::class); /* @phpstan-ignore method.notFound */
                $this->parseTestMethodAttributes($app, UsesFrameworkConfiguration::class); /* @phpstan-ignore method.notFound */
            },
        );

        $app->make(FoundationLoadConfiguration::class)->bootstrap($app);
        $app->make(SyncDatabaseEnvironmentVariables::class)->bootstrap($app);

        if (($timezone = $this->getApplicationTimezone($app)) !== null) {
            $app->make('config')->set('app.timezone', $timezone);
            date_default_timezone_set($timezone);
        }

        // Rewrite the default database name for parallel testing before
        // defineEnvironment() runs, so custom connections derived from
        // the default connection inherit the per-worker database name.
        $this->configureParallelDatabaseName($app);

        if (is_string($bootstrapProviderPath = $this->getApplicationBootstrapFile('providers.php'))) {
            TestbenchRegisterProviders::merge([], $bootstrapProviderPath);
        }

        $this->resolveApplicationProviders($app);
        $this->registerPackageAliases($app);

        TestingFeature::run(
            testCase: $this,
            attribute: fn () => $this->parseTestMethodAttributes($app, WithConfig::class), /* @phpstan-ignore method.notFound */
        );
    }

    /**
     * Resolve the final application provider list.
     *
     * Merges package providers, then applies overrides (replacements/removals)
     * before writing the final list to config for RegisterProviders to use.
     */
    protected function resolveApplicationProviders(ApplicationContract $app): void
    {
        $providers = (new Collection(TestbenchRegisterProviders::mergeAdditionalProvidersForTestbench(
            $this->getApplicationProviders($app)
        )))
            ->merge($this->getPackageProviders($app));

        $overrides = $this->overrideApplicationProviders($app);

        if (! empty($overrides)) {
            $providers = $providers->map(static function (string $provider) use ($overrides) {
                if (! array_key_exists($provider, $overrides)) {
                    return $provider;
                }

                $replacement = $overrides[$provider];

                return $replacement !== false ? $replacement : null;
            })->filter()->values();
        }

        $app->make('config')->set('app.providers', $providers->all());
    }

    /**
     * Run bootstrappers individually with defineEnvironment() inserted.
     *
     * Equivalent to Orchestral's resolveApplicationBootstrappers().
     */
    protected function resolveApplicationBootstrappers(ApplicationContract $app): void
    {
        $app->make(
            $this->isRunningTestCase()
                ? TestbenchHandleExceptions::class
                : FoundationHandleExceptions::class
        )->bootstrap($app);

        // Must be swapped BEFORE RegisterFacades and RegisterProviders, which both
        // read from the package manifest during bootstrap.
        PackageManifest::swap($app, $this);

        $app->make(RegisterFacades::class)->bootstrap($app);
        $app->make(TestbenchRegisterProviders::class)->bootstrap($app);
        $app->make(GenerateProxies::class)->bootstrap($app);

        // Define environment between RegisterProviders and BootProviders.
        // Matches Orchestral's pattern for database config etc.
        TestingFeature::run(
            testCase: $this,
            default: fn () => $this->defineEnvironment($app),
            attribute: fn () => $this->parseTestMethodAttributes($app, DefineEnvironment::class), /* @phpstan-ignore method.notFound */
            pest: fn () => $this->defineEnvironmentUsingPest($app), /* @phpstan-ignore method.notFound */
        );

        if (static::usesTestingConcern(WithWorkbench::class)) {
            $this->bootDiscoverRoutesForWorkbench($app); /* @phpstan-ignore method.notFound */
        }

        $app->make(BootProviders::class)->bootstrap($app);

        foreach ($this->getPackageBootstrappers($app) as $bootstrapper) {
            $app->make($bootstrapper)->bootstrap($app);
        }

        // Override the normal ConnectionResolver (registered by DatabaseServiceProvider)
        // with the testing resolver that caches connections statically to prevent pool
        // exhaustion. Only for test cases — remote subprocesses (e.g. queue:work) need
        // the real pool-based resolver for proper coroutine lifecycle.
        if ($this->isRunningTestCase()) {
            $app->singleton(ConnectionResolverInterface::class, DatabaseConnectionResolver::class);
            Model::setConnectionResolver($app->make(ConnectionResolverInterface::class));
        }

        $app->make(KernelContract::class)->bootstrap();
    }

    /**
     * Refresh route name lookups now and whenever the URL generator is resolved.
     *
     * Route names set via fluent ->name() after RouteCollection::add() are not
     * indexed until refreshNameLookups() rebuilds the lookup table. This method
     * ensures names are refreshed both immediately and lazily — the after_resolving
     * callback catches routes defined inside test methods (after boot) by firing
     * whenever app('url') is resolved.
     */
    protected function refreshApplicationRouteNameLookups(ApplicationContract $app): void
    {
        /** @var Router $router */
        $router = $app->make('router');

        refresh_router_lookups($router);

        after_resolving($app, 'url', static function () use ($router): void {
            refresh_router_lookups($router);
        });
    }

    /**
     * Register package aliases into config.
     */
    protected function registerPackageAliases(ApplicationContract $app): void
    {
        $aliases = $this->getPackageAliases($app);

        if (empty($aliases)) {
            return;
        }

        $config = $app->make('config');
        $existing = $config->get('app.aliases', []);
        $config->set('app.aliases', array_merge($existing, $aliases));
    }

    /**
     * Configure worker-specific cache paths for parallel testing.
     *
     * When running under ParaTest, each worker gets a unique route cache
     * path to prevent filesystem races. Without this, one worker's
     * defineCacheRoutes() writes a shared cache file that causes other
     * workers' routesAreCached() to return true, skipping route setup.
     */
    protected function configureParallelCachePaths(): void
    {
        $token = env('TEST_TOKEN');

        if ($token === null) {
            return;
        }

        $_SERVER['APP_ROUTES_CACHE'] = "cache/routes-v7-test-{$token}.php";
    }
}
