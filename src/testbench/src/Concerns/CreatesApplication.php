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
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use Workbench\App\Exceptions\ExceptionHandler;

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

        $this->registerPackageProviders($app);
        $this->registerPackageAliases($app);
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
     * Register package providers into config.
     *
     * Merges the test's package providers into config('app.providers') so
     * they are registered by RegisterProviders during bootstrap.
     */
    protected function registerPackageProviders(ApplicationContract $app): void
    {
        $packageProviders = $this->getPackageProviders($app);

        if (empty($packageProviders)) {
            return;
        }

        $config = $app->make('config');
        $existing = $config->get('app.providers', []);
        $config->set('app.providers', array_merge($existing, $packageProviders));
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
