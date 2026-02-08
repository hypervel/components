<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hyperf\Contract\TranslatorLoaderInterface;
use Hyperf\ViewEngine\Compiler\BladeCompiler;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactoryContract;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Router\RouteFileCollector;
use Hypervel\Support\Facades\Artisan;

abstract class ServiceProvider
{
    /**
     * All of the registered booting callbacks.
     */
    protected array $bootingCallbacks = [];

    /**
     * All of the registered booted callbacks.
     */
    protected array $bootedCallbacks = [];

    /**
     * The paths that should be published.
     */
    public static array $publishes = [];

    /**
     * The paths that should be published by group.
     */
    public static array $publishGroups = [];

    /**
     * The migration paths available for publishing.
     *
     * @var array
     */
    protected static $publishableMigrationPaths = [];

    public function __construct(
        protected ApplicationContract $app
    ) {
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Register a booting callback to be run before the "boot" method is called.
     */
    public function booting(Closure $callback): void
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a booted callback to be run after the "boot" method is called.
     */
    public function booted(Closure $callback): void
    {
        $this->bootedCallbacks[] = $callback;
    }

    /**
     * Call the registered booting callbacks.
     */
    public function callBootingCallbacks(): void
    {
        foreach ($this->bootingCallbacks as $callback) {
            $callback($this->app);
        }
    }

    /**
     * Call the registered booted callbacks.
     */
    public function callBootedCallbacks(): void
    {
        foreach ($this->bootedCallbacks as $callback) {
            $callback($this->app);
        }
    }

    /**
     * Merge the given configuration with the existing configuration.
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        $config = $this->app->get(Repository::class);
        $config->set($key, array_merge(
            require $path,
            $config->get($key, [])
        ));
    }

    /**
     * Load the given routes file if routes are not already cached.
     */
    protected function loadRoutesFrom(string $path): void
    {
        $this->app->get(RouteFileCollector::class)
            ->addRouteFile($path);
    }

    /**
     * Register a view file namespace.
     */
    protected function loadViewsFrom(array|string $path, string $namespace): void
    {
        $this->callAfterResolving(ViewFactoryContract::class, function ($view) use ($path, $namespace) {
            $viewPath = $this->app->get(Repository::class)
                ->get('view.config.view_path', null);

            if (is_dir($appPath = $viewPath . '/vendor/' . $namespace)) {
                $view->addNamespace($namespace, $appPath);
            }

            $view->addNamespace($namespace, $path);
        });
    }

    /**
     * Register the given view components with a custom prefix.
     */
    protected function loadViewComponentsAs(string $prefix, array $components): void
    {
        $this->callAfterResolving(BladeCompiler::class, function ($blade) use ($prefix, $components) {
            foreach ($components as $alias => $component) {
                $blade->component($component, is_string($alias) ? $alias : null, $prefix);
            }
        });
    }

    /**
     * Register a translation file namespace.
     */
    protected function loadTranslationsFrom(string $path, string $namespace): void
    {
        $this->callAfterResolving(TranslatorLoaderInterface::class, function ($translator) use ($path, $namespace) {
            $translator->addNamespace($namespace, $path);
        });
    }

    /**
     * Register a JSON translation file path.
     */
    protected function loadJsonTranslationsFrom(string $path): void
    {
        $this->callAfterResolving(TranslatorLoaderInterface::class, function ($translator) use ($path) {
            $translator->addJsonPath($path);
        });
    }

    /**
     * Register database migration paths.
     */
    protected function loadMigrationsFrom(array|string $paths): void
    {
        $this->callAfterResolving(Migrator::class, function ($migrator) use ($paths) {
            foreach ((array) $paths as $path) {
                $migrator->path($path);
            }
        });
    }

    /**
     * Setup an after resolving listener, or fire immediately if already resolved.
     */
    protected function callAfterResolving(string $name, Closure $callback): void
    {
        $this->app->afterResolving($name, $callback);

        if ($this->app->resolved($name)) {
            $callback($this->app->get($name), $this->app);
        }
    }

    /**
     * Register migration paths to be published by the publish command.
     */
    protected function publishesMigrations(array $paths, mixed $groups = null): void
    {
        $this->publishes($paths, $groups);

        static::$publishableMigrationPaths = array_unique(
            array_merge(
                static::$publishableMigrationPaths,
                array_keys($paths)
            )
        );
    }

    /**
     * Register paths to be published by the publish command.
     */
    protected function publishes(array $paths, mixed $groups = null): void
    {
        $this->ensurePublishArrayInitialized($class = static::class);

        static::$publishes[$class] = array_merge(static::$publishes[$class], $paths);

        foreach ((array) $groups as $group) {
            $this->addPublishGroup($group, $paths);
        }
    }

    /**
     * Ensure the publish array for the service provider is initialized.
     */
    protected function ensurePublishArrayInitialized(string $class): void
    {
        if (! array_key_exists($class, static::$publishes)) {
            static::$publishes[$class] = [];
        }
    }

    /**
     * Add a publish group / tag to the service provider.
     */
    protected function addPublishGroup(string $group, array $paths): void
    {
        if (! array_key_exists($group, static::$publishGroups)) {
            static::$publishGroups[$group] = [];
        }

        static::$publishGroups[$group] = array_merge(
            static::$publishGroups[$group],
            $paths
        );
    }

    /**
     * Get the paths to publish.
     */
    public static function pathsToPublish(?string $provider = null, ?string $group = null): array
    {
        if (! is_null($paths = static::pathsForProviderOrGroup($provider, $group))) { // @phpstan-ignore function.impossibleType (logic bug: method always returns array, fix in separate PR)
            return $paths;
        }

        // @phpstan-ignore deadCode.unreachable (logic bug: method always returns array, fix in separate PR)
        return collect(static::$publishes)->reduce(function ($paths, $p) {
            return array_merge($paths, $p);
        }, []);
    }

    /**
     * Get the paths for the provider or group (or both).
     *
     * Returns null when no filter is specified, allowing caller to fall back to all paths.
     * Returns empty array when a filter is specified but not found.
     */
    protected static function pathsForProviderOrGroup(?string $provider, ?string $group): ?array
    {
        if ($provider && $group) {
            return static::pathsForProviderAndGroup($provider, $group);
        }
        if ($group && array_key_exists($group, static::$publishGroups)) {
            return static::$publishGroups[$group];
        }
        if ($provider && array_key_exists($provider, static::$publishes)) {
            return static::$publishes[$provider];
        }

        // Return [] if a filter was specified but not found
        // Return null if no filter was specified (allows fallback to all paths)
        return ($provider || $group) ? [] : null;
    }

    /**
     * Get the paths for the provider and group.
     */
    protected static function pathsForProviderAndGroup(string $provider, string $group): array
    {
        if (! empty(static::$publishes[$provider]) && ! empty(static::$publishGroups[$group])) {
            return array_intersect_key(static::$publishes[$provider], static::$publishGroups[$group]);
        }

        return [];
    }

    /**
     * Get the service providers available for publishing.
     */
    public static function publishableProviders(): array
    {
        return array_keys(static::$publishes);
    }

    /**
     * Get the migration paths available for publishing.
     */
    public static function publishableMigrationPaths(): array
    {
        return static::$publishableMigrationPaths;
    }

    /**
     * Get the groups available for publishing.
     */
    public static function publishableGroups(): array
    {
        return array_keys(static::$publishGroups);
    }

    /**
     * Register the package's custom Artisan commands.
     */
    public function commands(array $commands): void
    {
        Artisan::addCommands($commands);
    }

    /**
     * Get the default providers for a Laravel application.
     */
    public static function defaultProviders(): DefaultProviders
    {
        return new DefaultProviders();
    }

    /**
     * Get the provider config for Hyperf framework.
     */
    public static function getProviderConfig(): array
    {
        return [];
    }
}
