<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hypervel\Console\Application as Artisan;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Contracts\Translation\Loader as TranslationLoader;
use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\View\Compilers\CompilerInterface;
use ReflectionClass;
use ReflectionProperty;

abstract class ServiceProvider
{
    /**
     * The registration priority for this provider.
     *
     * Higher values are registered first among discovered/merged providers.
     * Core framework providers (DefaultProviders) always load first regardless
     * of priority. Use gaps between values (10, 20, 30) to allow future
     * insertion without renumbering.
     */
    public int $priority = 0;

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
     *
     * Top-level keys use shallow merge (app values override package defaults).
     * Keys declared in mergeableOptions() get an additional one-level-deeper
     * merge so the app can add entries to collection arrays (stores, connections,
     * guards, etc.) without losing the package's default entries.
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        $config = $this->app->make('config');

        $packageDefaults = require $path;
        $appConfig = $config->get($key, []);

        $merged = array_merge($packageDefaults, $appConfig);

        foreach ($this->mergeableOptions($key) as $option) {
            if (isset($packageDefaults[$option], $appConfig[$option])) {
                $merged[$option] = array_merge($packageDefaults[$option], $appConfig[$option]);
            }
        }

        $config->set($key, $merged);
    }

    /**
     * Get the options within the configuration that should be merged.
     *
     * Override this in package service providers to declare which config keys
     * contain collection arrays that should be merged rather than replaced.
     * This uses the same two-level merge logic as LoadConfiguration::mergeableOptions().
     *
     * With mergeableOptions() returning ['stores']:
     *   - Package defines stores: array, file, redis, swoole
     *   - App defines stores: redis (custom config), s3 (new)
     *   - Result: array, file, redis (app's version — fully replaced, no package keys leak in), swoole, s3
     *
     * Without 'stores' in mergeableOptions():
     *   - Package defines stores: array, file, redis, swoole
     *   - App defines stores: redis (custom config), s3 (new)
     *   - Result: redis (app's version), s3 — everything else gone
     *
     * @return array<int, string>
     */
    protected function mergeableOptions(string $name): array
    {
        return [];
    }

    /**
     * Load the given routes file if routes are not already cached.
     */
    protected function loadRoutesFrom(string $path): void
    {
        if (! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            require $path;
        }
    }

    /**
     * Register a view file namespace.
     */
    protected function loadViewsFrom(array|string $path, string $namespace): void
    {
        $this->callAfterResolving(ViewFactoryContract::class, function ($view) use ($path, $namespace) {
            if (isset($this->app->config['view']['paths'])
                && is_array($this->app->config['view']['paths'])) {
                foreach ($this->app->config['view']['paths'] as $viewPath) {
                    if (is_dir($appPath = $viewPath . '/vendor/' . $namespace)) {
                        $view->addNamespace($namespace, $appPath);
                    }
                }
            }

            $view->addNamespace($namespace, $path);
        });
    }

    /**
     * Register the given view components with a custom prefix.
     */
    protected function loadViewComponentsAs(string $prefix, array $components): void
    {
        $this->callAfterResolving(CompilerInterface::class, function ($blade) use ($prefix, $components) {
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
        $this->callAfterResolving(TranslationLoader::class, function ($translator) use ($path, $namespace) {
            $translator->addNamespace($namespace, $path);
        });
    }

    /**
     * Register a JSON translation file path.
     */
    protected function loadJsonTranslationsFrom(string $path): void
    {
        $this->callAfterResolving(TranslationLoader::class, function ($translator) use ($path) {
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
            $callback($this->app->make($name), $this->app);
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
     * Register config paths to be published by the publish command.
     *
     * Automatically adds the 'config' group alongside any package-specific groups,
     * enabling ConfigPublishCommand to discover all publishable config files.
     */
    protected function publishesConfig(array $paths, string|array $groups = []): void
    {
        $this->publishes($paths, array_merge(['config'], (array) $groups));
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
     * Flush all static publish state.
     */
    public static function flushState(): void
    {
        static::$publishes = [];
        static::$publishGroups = [];
        static::$publishableMigrationPaths = [];
    }

    /**
     * Add a provider to the bootstrap provider configuration file.
     */
    public static function addProviderToBootstrapFile(string $provider, ?string $path = null): bool
    {
        $path ??= app()->getBootstrapProvidersPath();

        if (! file_exists($path)) {
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        $providers = (new Collection(require $path))
            ->merge([$provider])
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($p) => '    ' . $p . '::class,')
            ->implode(PHP_EOL);

        $content = '<?php

return [
' . $providers . '
];';

        file_put_contents($path, $content . PHP_EOL);

        return true;
    }

    /**
     * Register the package's custom Artisan commands.
     */
    public function commands(array $commands): void
    {
        Artisan::starting(function ($artisan) use ($commands) {
            $artisan->resolveCommands($commands);
        });
    }

    /**
     * Register AOP aspects.
     *
     * Reads `$classes` and `$priority` from each aspect class's default
     * property values via reflection (without instantiating the aspect).
     * Must be called during register(), before boot().
     *
     * @param array<int, string>|string $aspects
     */
    protected function aspects(string|array $aspects): void
    {
        $aspects = is_array($aspects) ? $aspects : func_get_args();

        foreach ($aspects as $aspect) {
            $reflectionClass = new ReflectionClass($aspect);
            $properties = $reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);

            $classes = [];
            $priority = null;

            foreach ($properties as $property) {
                if ($property->getName() === 'classes') {
                    $classes = $property->getDefaultValue();
                } elseif ($property->getName() === 'priority') {
                    $priority = $property->getDefaultValue();
                }
            }

            AspectCollector::setAround($aspect, $classes, $priority);
        }
    }

    /**
     * Register class map overrides.
     *
     * Applies entries to the Composer autoloader immediately.
     * Fails hard if any target class is already loaded.
     * Must be called during register(), before the target class is autoloaded.
     *
     * @param array<class-string, string> $map originalClass => replacementFilePath
     */
    protected function classMap(array $map): void
    {
        ClassMapManager::add($map);
    }

    /**
     * Get the default providers for a Hypervel application.
     */
    public static function defaultProviders(): DefaultProviders
    {
        return new DefaultProviders();
    }
}
