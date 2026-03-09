<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\BootProviders;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Routing\Router;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use Workbench\App\Exceptions\ExceptionHandler;

use function Hypervel\Testbench\after_resolving;
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
    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [];
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
        $app = $this->resolveApplication();

        $this->resolveApplicationBindings($app);
        $this->resolveApplicationConfiguration($app);
        $this->resolveApplicationBootstrappers($app);
        $this->refreshApplicationRouteNameLookups($app);

        return $app;
    }

    /**
     * Create the default application instance.
     */
    protected function resolveApplication(): ApplicationContract
    {
        $app = new Application();

        // Use testbench Console Kernel with empty bootstrappers() so that
        // ConsoleKernel::bootstrap() at the end of the lifecycle only sets
        // hasBeenBootstrapped without re-running any bootstrappers.
        $app->singleton(KernelContract::class, \Hypervel\Testbench\Console\Kernel::class);
        $app->singleton(HttpKernelContract::class, \Hypervel\Testbench\Http\Kernel::class);
        $app->singleton(ExceptionHandlerContract::class, ExceptionHandler::class);

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
     * Load configuration and register package providers/aliases.
     *
     * Equivalent to Orchestral's resolveApplicationConfiguration(): loads
     * config files, then sets app.providers and app.aliases in config
     * BEFORE RegisterProviders reads them.
     */
    protected function resolveApplicationConfiguration(ApplicationContract $app): void
    {
        $app->bootstrapWith([LoadEnvironmentVariables::class]);

        $app->make(LoadConfiguration::class)->bootstrap($app);

        $this->resolveApplicationProviders($app);
        $this->registerPackageAliases($app);
    }

    /**
     * Resolve the final application provider list.
     *
     * Merges package providers, then applies overrides (replacements/removals)
     * before writing the final list to config for RegisterProviders to use.
     */
    protected function resolveApplicationProviders(ApplicationContract $app): void
    {
        $providers = (new Collection($this->getApplicationProviders($app)))
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
        $app->make(RegisterFacades::class)->bootstrap($app);
        $app->make(RegisterProviders::class)->bootstrap($app);
        $app->make(GenerateProxies::class)->bootstrap($app);

        // Define environment and process DefineEnvironment attributes —
        // modify config BEFORE providers boot. Matches Orchestral's
        // pattern of calling defineEnvironment() alongside attributes
        // between RegisterProviders and BootProviders.
        $this->defineEnvironment($app);
        $this->resolvePhpUnitAttributes()
            ->filter(static fn ($attrs, string $key) => $key === DefineEnvironment::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof Actionable)
            ->each(fn ($instance) => $instance->handle(
                $app,
                fn ($method, $parameters) => $this->{$method}(...$parameters)
            ));

        $app->make(BootProviders::class)->bootstrap($app);

        // Override the normal ConnectionResolver (registered by DatabaseServiceProvider)
        // with the testing resolver that caches connections statically to prevent pool
        // exhaustion. Must happen AFTER providers register/boot so it wins.
        $app->singleton(ConnectionResolverInterface::class, DatabaseConnectionResolver::class);
        Model::setConnectionResolver($app->make(ConnectionResolverInterface::class));

        // Finalize — sets hasBeenBootstrapped, loads deferred providers + commands.
        $app->make(ConsoleApplicationContract::class);
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
}
