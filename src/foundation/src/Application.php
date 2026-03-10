<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Closure;
use Composer\Autoload\ClassLoader;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Foundation\CachesConfiguration;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Events\LocaleUpdated;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Env;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function Hypervel\Filesystem\join_paths;

class Application extends Container implements ApplicationContract, CachesConfiguration, CachesRoutes
{
    use Macroable;

    /**
     * The Hypervel framework version.
     *
     * @var string
     */
    public const VERSION = '0.4';

    /**
     * The base path for the Hypervel installation.
     */
    protected string $basePath = '';

    /**
     * The path to the bootstrap directory.
     */
    protected string $bootstrapPath = '';

    /**
     * The custom storage path defined by the developer.
     */
    protected ?string $storagePath = null;

    /**
     * The custom environment path defined by the developer.
     */
    protected ?string $environmentPath = null;

    /**
     * The environment file to load during bootstrapping.
     */
    protected ?string $environmentFile = null;

    /**
     * The prefixes that indicate an absolute cache path.
     *
     * @var string[]
     */
    protected array $absoluteCachePathPrefixes = ['/', '\\'];

    /**
     * Indicates if the application has been bootstrapped before.
     */
    protected bool $hasBeenBootstrapped = false;

    /**
     * Indicates if the application is running in the console.
     */
    protected ?bool $isRunningInConsole = null;

    /**
     * Indicates if the application has "booted".
     */
    protected bool $booted = false;

    /**
     * The array of booting callbacks.
     *
     * @var callable[]
     */
    protected array $bootingCallbacks = [];

    /**
     * The array of booted callbacks.
     *
     * @var callable[]
     */
    protected array $bootedCallbacks = [];

    /**
     * The array of terminating callbacks.
     *
     * @var array<callable|string>
     */
    protected array $terminatingCallbacks = [];

    /**
     * The array of registered callbacks.
     *
     * @var callable[]
     */
    protected array $registeredCallbacks = [];

    /**
     * All of the registered service providers.
     *
     * @var array<string, ServiceProvider>
     */
    protected array $serviceProviders = [];

    /**
     * The names of the loaded service providers.
     */
    protected array $loadedProviders = [];

    /**
     * The application namespace.
     */
    protected ?string $namespace;

    public function __construct(?string $basePath = null)
    {
        $this->setBasePath($basePath ?: (defined('BASE_PATH') ? BASE_PATH : ''));

        $this->registerBaseBindings();
        $this->registerBaseServiceProviders();
        $this->registerCoreContainerAliases();
    }

    /**
     * Configure and create a new application builder instance.
     */
    public static function configure(?string $basePath = null): Configuration\ApplicationBuilder
    {
        $basePath = match (true) {
            is_string($basePath) => $basePath,
            default => static::inferBasePath(),
        };

        return (new Configuration\ApplicationBuilder(new static($basePath)))
            ->withKernels()
            ->withEvents()
            ->withCommands()
            ->withProviders();
    }

    /**
     * Infer the application's base directory from the environment.
     */
    public static function inferBasePath(): string
    {
        return match (true) {
            isset($_ENV['APP_BASE_PATH']) => $_ENV['APP_BASE_PATH'],
            isset($_SERVER['APP_BASE_PATH']) => $_SERVER['APP_BASE_PATH'],
            default => dirname(array_values(array_filter(
                array_keys(ClassLoader::getRegisteredLoaders()),
                fn ($path) => ! str_starts_with($path, 'phar://'),
            ))[0]),
        };
    }

    /**
     * Get the version number of the application.
     */
    public function version(): string
    {
        return static::VERSION;
    }

    /**
     * Register the basic bindings into the container.
     */
    protected function registerBaseBindings(): void
    {
        static::setInstance($this);

        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerContract::class, $this);
        $this->instance(ApplicationContract::class, $this);
        $this->instance(\Psr\Container\ContainerInterface::class, $this);

        // Console application must be bound before service providers because
        // resolving it triggers Kernel::getArtisan() which calls bootstrap().
        $this->singleton(
            \Hypervel\Contracts\Console\Application::class,
            fn ($app) => $app->make(\Hypervel\Contracts\Console\Kernel::class)->getArtisan()
        );

        // StdoutLogger is resolved during Kernel bootstrap before service
        // providers run, so the binding must exist here.
        $this->singleton(
            \Hypervel\Contracts\Log\StdoutLoggerInterface::class,
            \Hypervel\Framework\Logger\StdoutLogger::class
        );
    }

    /**
     * Register all of the base service providers.
     */
    protected function registerBaseServiceProviders(): void
    {
        $this->register(new \Hypervel\Events\EventServiceProvider($this));
        $this->register(new \Hypervel\Routing\RoutingServiceProvider($this));
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * The facade system calls has() before get() to check if a service is
     * resolvable. With auto-singleton semantics, any concrete class can be
     * resolved, so has() must return true for existing classes.
     */
    public function has(string $id): bool
    {
        return parent::has($id) || class_exists($id);
    }

    /**
     * Run the given array of bootstrap classes.
     *
     * @param string[] $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void
    {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->dispatch('bootstrapping: ' . $bootstrapper, [$this]);

            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->dispatch('bootstrapped: ' . $bootstrapper, [$this]);
        }
    }

    /**
     * Register a callback to run before a bootstrapper.
     */
    public function beforeBootstrapping(string $bootstrapper, Closure $callback): void
    {
        $this['events']->listen('bootstrapping: ' . $bootstrapper, $callback);
    }

    /**
     * Register a callback to run after a bootstrapper.
     */
    public function afterBootstrapping(string $bootstrapper, Closure $callback): void
    {
        $this['events']->listen('bootstrapped: ' . $bootstrapper, $callback);
    }

    /**
     * Register a callback to run after loading the environment.
     */
    public function afterLoadingEnvironment(Closure $callback): void
    {
        $this->afterBootstrapping(
            Bootstrap\LoadEnvironmentVariables::class,
            $callback
        );
    }

    /**
     * Determine if the application has been bootstrapped before.
     */
    public function hasBeenBootstrapped(): bool
    {
        return $this->hasBeenBootstrapped;
    }

    /**
     * Set the base path for the application.
     *
     * @return $this
     */
    public function setBasePath(string $basePath): static
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->bootstrapPath = $this->basePath('bootstrap');

        return $this;
    }

    /**
     * Get the path to the application "app" directory.
     */
    public function path(string $path = ''): string
    {
        return $this->joinPaths($this->basePath('app'), $path);
    }

    /**
     * Get the base path of the Hypervel installation.
     */
    public function basePath(string $path = ''): string
    {
        return $this->joinPaths($this->basePath, $path);
    }

    /**
     * Get the path to the bootstrap directory.
     */
    public function bootstrapPath(string $path = ''): string
    {
        return $this->joinPaths($this->bootstrapPath, $path);
    }

    /**
     * Set the bootstrap file directory.
     */
    public function useBootstrapPath(string $path): static
    {
        $this->bootstrapPath = $path;

        $this->instance('path.bootstrap', $path);

        return $this;
    }

    /**
     * Get the path to the service provider list in the bootstrap directory.
     */
    public function getBootstrapProvidersPath(): string
    {
        return $this->bootstrapPath('providers.php');
    }

    /**
     * Get the path to the application configuration files.
     */
    public function configPath(string $path = ''): string
    {
        return $this->joinPaths($this->basePath('config'), $path);
    }

    /**
     * Get the path to the database directory.
     */
    public function databasePath(string $path = ''): string
    {
        return $this->joinPaths($this->basePath('database'), $path);
    }

    /**
     * Get the path to the language files.
     */
    public function langPath(string $path = ''): string
    {
        return $this->joinPaths($this->basePath('lang'), $path);
    }

    /**
     * Get the path to the public directory.
     */
    public function publicPath(string $path = ''): string
    {
        return $this->joinPaths($this->basePath('public'), $path);
    }

    /**
     * Get the path to the resources directory.
     */
    public function resourcePath(string $path = ''): string
    {
        return $this->joinPaths($this->basePath('resources'), $path);
    }

    /**
     * Get the path to the views directory.
     *
     * This method returns the first configured path in the array of view paths.
     */
    public function viewPath(string $path = ''): string
    {
        $viewPath = rtrim(
            $this['config']->get('view.config.view_path') ?: $this->basePath('resources/views'),
            DIRECTORY_SEPARATOR
        );

        return $this->joinPaths($viewPath, $path);
    }

    /**
     * Get the path to the storage directory.
     */
    public function storagePath(string $path = ''): string
    {
        if (isset($_ENV['HYPERVEL_STORAGE_PATH'])) {
            return $this->joinPaths($this->storagePath ?: $_ENV['HYPERVEL_STORAGE_PATH'], $path);
        }

        if (isset($_SERVER['HYPERVEL_STORAGE_PATH'])) {
            return $this->joinPaths($this->storagePath ?: $_SERVER['HYPERVEL_STORAGE_PATH'], $path);
        }

        return $this->joinPaths($this->storagePath ?: $this->basePath('storage'), $path);
    }

    /**
     * Set the storage directory.
     */
    public function useStoragePath(string $path): static
    {
        $this->storagePath = $path;

        $this->instance('path.storage', $path);

        return $this;
    }

    /**
     * Get the path to the environment file directory.
     */
    public function environmentPath(): string
    {
        return $this->environmentPath ?: $this->basePath;
    }

    /**
     * Set the directory for the environment file.
     *
     * @return $this
     */
    public function useEnvironmentPath(string $path): static
    {
        $this->environmentPath = $path;

        return $this;
    }

    /**
     * Set the environment file to be loaded during bootstrapping.
     *
     * @return $this
     */
    public function loadEnvironmentFrom(string $file): static
    {
        $this->environmentFile = $file;

        return $this;
    }

    /**
     * Get the environment file the application is using.
     */
    public function environmentFile(): string
    {
        return $this->environmentFile ?: '.env';
    }

    /**
     * Get the fully qualified path to the environment file.
     */
    public function environmentFilePath(): string
    {
        return $this->environmentPath() . DIRECTORY_SEPARATOR . $this->environmentFile();
    }

    /**
     * Determine if the application configuration is cached.
     */
    public function configurationIsCached(): bool
    {
        return is_file($this->getCachedConfigPath());
    }

    /**
     * Get the path to the configuration cache file.
     */
    public function getCachedConfigPath(): string
    {
        return $this->normalizeCachePath('APP_CONFIG_CACHE', 'cache/config.php');
    }

    /**
     * Get the path to the cached services.php file.
     */
    public function getCachedServicesPath(): string
    {
        return $this->normalizeCachePath('APP_SERVICES_CACHE', 'cache/services.php');
    }

    /**
     * Determine if the application routes are cached.
     */
    public function routesAreCached(): bool
    {
        return is_file($this->getCachedRoutesPath());
    }

    /**
     * Get the path to the routes cache file.
     */
    public function getCachedRoutesPath(): string
    {
        return $this->normalizeCachePath('APP_ROUTES_CACHE', 'cache/routes-v7.php');
    }

    /**
     * Normalize a relative or absolute path to a cache file.
     */
    protected function normalizeCachePath(string $key, string $default): string
    {
        if (is_null($env = Env::get($key))) {
            return $this->bootstrapPath($default);
        }

        return Str::startsWith($env, $this->absoluteCachePathPrefixes)
            ? $env
            : $this->basePath($env);
    }

    /**
     * Join the given paths together.
     */
    public function joinPaths(string $basePath, string $path = ''): string
    {
        return join_paths($basePath, $path);
    }

    /**
     * Get or check the current application environment.
     *
     * @param array|string ...$environments
     */
    public function environment(...$environments): bool|string
    {
        if (count($environments) > 0) {
            $patterns = is_array($environments[0]) ? $environments[0] : $environments;

            return Str::is($patterns, $this['env']);
        }

        return $this['env'];
    }

    /**
     * Determine if the application is in the local environment.
     */
    public function isLocal(): bool
    {
        return $this['env'] === 'local';
    }

    /**
     * Determine if the application is in the production environment.
     */
    public function isProduction(): bool
    {
        return $this['env'] === 'production';
    }

    /**
     * Detect the application's current environment.
     */
    public function detectEnvironment(Closure $callback): string
    {
        $args = $this->runningInConsole() && isset($_SERVER['argv'])
            ? $_SERVER['argv']
            : null;

        return $this['env'] = (new EnvironmentDetector())->detect($callback, $args);
    }

    /**
     * Determine if the application is running in the console.
     *
     * In Swoole, PHP_SAPI is always 'cli', so this defaults to true.
     * The server command sets it to false before starting the HTTP server,
     * and workers inherit that value.
     */
    public function runningInConsole(): bool
    {
        if ($this->isRunningInConsole !== null) {
            return $this->isRunningInConsole;
        }

        return $this->isRunningInConsole = (bool) (Env::get('APP_RUNNING_IN_CONSOLE') ?? true);
    }

    /**
     * Determine if the application is running any of the given console commands.
     */
    public function runningConsoleCommand(string|array ...$commands): bool
    {
        if (! $this->runningInConsole()) {
            return false;
        }

        return in_array(
            $_SERVER['argv'][1] ?? null,
            is_array($commands[0] ?? null) ? $commands[0] : $commands
        );
    }

    /**
     * Set whether the application is running in the console.
     */
    public function setRunningInConsole(bool $runningInConsole): void
    {
        $this->isRunningInConsole = $runningInConsole;
    }

    /**
     * Determine if the application is running unit tests.
     */
    public function runningUnitTests(): bool
    {
        return $this->bound('env') && $this['env'] === 'testing';
    }

    /**
     * Determine if the application is running with debug mode enabled.
     */
    public function hasDebugModeEnabled(): bool
    {
        return (bool) $this['config']->get('app.debug');
    }

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @TODO Implement properly once maintenance mode is ported.
     */
    public function isDownForMaintenance(): bool
    {
        return false;
    }

    /**
     * Determine if middleware has been disabled for the application.
     */
    public function shouldSkipMiddleware(): bool
    {
        return $this->bound('middleware.disable')
            && $this->make('middleware.disable') === true;
    }

    /**
     * Register a new registered listener.
     */
    public function registered(callable $callback): void
    {
        $this->registeredCallbacks[] = $callback;
    }

    /**
     * Register all of the configured providers.
     *
     * Providers are loaded in three tiers:
     * 1. Framework providers (Hypervel\* from app.providers) — always first
     * 2. Discovered providers (from composer packages) — sorted by priority
     * 3. Application providers (non-Hypervel\* from app.providers) — always last
     */
    public function registerConfiguredProviders(): void
    {
        $providers = (new Collection($this->make('config')->get('app.providers', [])))
            ->partition(fn (string $provider) => str_starts_with($provider, 'Hypervel\\'));

        $discovered = static::sortByPriority($this->discoverProviders());

        $providers->splice(1, 0, [$discovered]);

        foreach ($providers->collapse()->unique()->all() as $provider) {
            $this->register($provider);
        }

        $this->fireAppCallbacks($this->registeredCallbacks);
    }

    /**
     * Discover service providers from installed composer packages.
     *
     * @return array<int, class-string>
     */
    protected function discoverProviders(): array
    {
        return RegisterProviders::discoveredProviders();
    }

    /**
     * Sort providers by their priority property in descending order.
     *
     * Higher priority values are loaded first. Providers with the same
     * priority preserve their original order (stable sort).
     *
     * @param array<int, class-string> $providers
     * @return array<int, class-string>
     */
    protected static function sortByPriority(array $providers): array
    {
        if (empty($providers)) {
            return [];
        }

        // Read priority from each provider's default property value without instantiation
        $prioritized = array_map(function (string $provider) {
            $priority = 0;

            if (class_exists($provider) && is_subclass_of($provider, ServiceProvider::class)) {
                $priority = (new ReflectionClass($provider))->getDefaultProperties()['priority'] ?? 0;
            }

            return ['provider' => $provider, 'priority' => $priority];
        }, $providers);

        // Stable sort: higher priority first, original order preserved within same priority
        usort($prioritized, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);

        return array_column($prioritized, 'provider');
    }

    /**
     * Register a service provider with the application.
     */
    public function register(ServiceProvider|string $provider, bool $force = false): ServiceProvider
    {
        if (($registered = $this->getProvider($provider)) && ! $force) {
            return $registered;
        }

        // If the given "provider" is a string, we will resolve it, passing in the
        // application instance automatically for the developer. This is simply
        // a more convenient way of specifying your service provider classes.
        if (is_string($provider)) {
            $provider = $this->resolveProvider($provider);
        }

        $provider->register();

        // If there are bindings / singletons set as properties on the provider we
        // will spin through them and register them with the application, which
        // serves as a convenience layer while registering a lot of bindings.
        if (property_exists($provider, 'bindings')) {
            foreach ($provider->bindings as $key => $value) {
                $this->bind($key, $value);
            }
        }

        if (property_exists($provider, 'singletons')) {
            foreach ($provider->singletons as $key => $value) {
                $key = is_int($key) ? $value : $key;
                $this->singleton($key, $value);
            }
        }

        $this->markAsRegistered($provider);

        // If the application has already booted, we will call this boot method on
        // the provider class so it has an opportunity to do its boot logic and
        // will be ready for any usage by this developer's application logic.
        if ($this->isBooted()) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    /**
     * Get the registered service provider instance if it exists.
     */
    public function getProvider(ServiceProvider|string $provider): ?ServiceProvider
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        return $this->serviceProviders[$name] ?? null;
    }

    /**
     * Get the registered service provider instances if any exist.
     */
    public function getProviders(ServiceProvider|string $provider): array
    {
        $name = is_string($provider) ? $provider : get_class($provider);

        return Arr::where($this->serviceProviders, fn ($value) => $value instanceof $name);
    }

    /**
     * Resolve a service provider instance from the class name.
     */
    public function resolveProvider(string $provider): ServiceProvider
    {
        return new $provider($this);
    }

    /**
     * Mark the given provider as registered.
     */
    protected function markAsRegistered(ServiceProvider $provider): void
    {
        $class = get_class($provider);

        $this->serviceProviders[$class] = $provider;

        $this->loadedProviders[$class] = true;
    }

    /**
     * Determine if the application has booted.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Boot the application's service providers.
     */
    public function boot(): void
    {
        if ($this->isBooted()) {
            return;
        }

        // Once the application has booted we will also fire some "booted" callbacks
        // for any listeners that need to do work after this initial booting gets
        // finished. This is useful when ordering the boot-up processes we run.
        $this->fireAppCallbacks($this->bootingCallbacks);

        array_walk($this->serviceProviders, function ($p) {
            $this->bootProvider($p);
        });

        $this->booted = true;

        $this->fireAppCallbacks($this->bootedCallbacks);
    }

    /**
     * Boot the given service provider.
     */
    protected function bootProvider(ServiceProvider $provider): void
    {
        $provider->callBootingCallbacks();

        if (method_exists($provider, 'boot')) {
            $this->call([$provider, 'boot']);
        }

        $provider->callBootedCallbacks();
    }

    /**
     * Register a new boot listener.
     */
    public function booting(callable $callback): void
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a new "booted" listener.
     */
    public function booted(callable $callback): void
    {
        $this->bootedCallbacks[] = $callback;

        if ($this->isBooted()) {
            $callback($this);
        }
    }

    /**
     * Register a terminating callback with the application.
     *
     * @return $this
     */
    public function terminating(callable|string $callback): static
    {
        $this->terminatingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Terminate the application.
     */
    public function terminate(): void
    {
        foreach ($this->terminatingCallbacks as $callback) {
            $this->call($callback);
        }
    }

    /**
     * Call the booting callbacks for the application.
     *
     * @param callable[] $callbacks
     */
    protected function fireAppCallbacks(array &$callbacks): void
    {
        $index = 0;

        while ($index < count($callbacks)) {
            $callbacks[$index]($this);

            ++$index;
        }
    }

    /**
     * Throw an HttpException with the given data.
     *
     * @throws HttpException
     * @throws NotFoundHttpException
     */
    public function abort(int $code, string $message = '', array $headers = []): never
    {
        if ($code === 404) {
            throw new NotFoundHttpException($message, headers: $headers);
        }

        throw new HttpException($code, $message, headers: $headers);
    }

    /**
     * Get the service providers that have been loaded.
     *
     * @return array<string, bool>
     */
    public function getLoadedProviders(): array
    {
        return $this->loadedProviders;
    }

    /**
     * Determine if the given service provider is loaded.
     */
    public function providerIsLoaded(string $provider): bool
    {
        return isset($this->loadedProviders[$provider]);
    }

    /**
     * Get the current application locale.
     */
    public function getLocale(): string
    {
        return $this['translator']->getLocale();
    }

    /**
     * Determine if the application locale is the given locale.
     */
    public function isLocale(string $locale): bool
    {
        return $this->getLocale() === $locale;
    }

    /**
     * Get the current application locale.
     */
    public function currentLocale(): string
    {
        return $this->getLocale();
    }

    /**
     * Get the current application fallback locale.
     */
    public function getFallbackLocale(): string
    {
        return $this['translator']->getFallback();
    }

    /**
     * Set the current application locale.
     */
    public function setLocale(string $locale): void
    {
        $this['translator']->setLocale($locale);

        $this['events']->dispatch(new LocaleUpdated($locale));
    }

    /**
     * Register the core class aliases in the container.
     *
     * The key is the abstract (a resolvable FQCN), and the values are aliases
     * that should resolve to it. This direction is important: aliases point TO
     * the FQCN, so resolving an alias follows through to a real class. The
     * reversed direction (short name as key) would require service provider
     * bindings for each short name, which don't exist yet.
     */
    protected function registerCoreContainerAliases(): void
    {
        foreach ([
            \Psr\Container\ContainerInterface::class => [
                'app',
                \Hypervel\Contracts\Container\Container::class,
                \Hypervel\Container\Container::class,
                \Hypervel\Contracts\Foundation\Application::class,
                \Hypervel\Foundation\Application::class,
            ],
            \Hypervel\Contracts\Console\Kernel::class => ['artisan'],
            'auth' => [
                \Hypervel\Auth\AuthManager::class,
                \Hypervel\Contracts\Auth\Factory::class,
            ],
            'auth.driver' => [\Hypervel\Contracts\Auth\Guard::class],
            'cache' => [
                \Hypervel\Cache\CacheManager::class,
                \Hypervel\Contracts\Cache\Factory::class,
            ],
            'cache.store' => [
                \Hypervel\Cache\Repository::class,
                \Hypervel\Contracts\Cache\Repository::class,
            ],
            'config' => [
                \Hypervel\Config\Repository::class,
                \Hypervel\Contracts\Config\Repository::class,
            ],
            'cookie' => [
                \Hypervel\Cookie\CookieManager::class,
                \Hypervel\Contracts\Cookie\Cookie::class,
            ],
            'db' => [\Hypervel\Database\DatabaseManager::class],
            'db.schema' => [\Hypervel\Database\Schema\SchemaProxy::class],
            'db.transactions' => [\Hypervel\Database\DatabaseTransactionsManager::class],
            'encrypter' => [
                \Hypervel\Encryption\Encrypter::class,
                \Hypervel\Contracts\Encryption\Encrypter::class,
                \Hypervel\Contracts\Encryption\StringEncrypter::class,
            ],
            'events' => [
                \Hypervel\Events\Dispatcher::class,
                \Hypervel\Contracts\Events\Dispatcher::class,
            ],
            'files' => [\Hypervel\Filesystem\Filesystem::class],
            'filesystem' => [
                \Hypervel\Filesystem\FilesystemManager::class,
                \Hypervel\Contracts\Filesystem\Factory::class,
            ],
            'filesystem.disk' => [\Hypervel\Contracts\Filesystem\Filesystem::class],
            'filesystem.cloud' => [\Hypervel\Contracts\Filesystem\Cloud::class],
            'hash' => [\Hypervel\Hashing\HashManager::class, \Hypervel\Contracts\Hashing\Hasher::class],
            'hash.driver' => [],
            'jwt' => [
                \Hypervel\JWT\JWTManager::class,
                \Hypervel\JWT\Contracts\ManagerContract::class,
            ],
            'log' => [
                \Hypervel\Log\LogManager::class,
                \Psr\Log\LoggerInterface::class,
            ],
            \Hypervel\Contracts\Mail\Factory::class => [
                'mail.manager',
                \Hypervel\Mail\MailManager::class,
            ],
            \Hypervel\Contracts\Mail\Mailer::class => ['mailer'],
            \Hypervel\Database\Migrations\Migrator::class => ['migrator'],
            'queue' => [
                \Hypervel\Queue\QueueManager::class,
                \Hypervel\Contracts\Queue\Factory::class,
                \Hypervel\Contracts\Queue\Monitor::class,
            ],
            'queue.connection' => [\Hypervel\Contracts\Queue\Queue::class],
            'queue.failer' => [\Hypervel\Queue\Failed\FailedJobProviderInterface::class],
            'queue.listener' => [\Hypervel\Queue\Listener::class],
            'queue.worker' => [\Hypervel\Queue\Worker::class],
            'redis' => [
                \Hypervel\Redis\Redis::class,
                \Hypervel\Contracts\Redis\Factory::class,
            ],
            'redis.connection' => [
                \Hypervel\Contracts\Redis\Connection::class,
            ],
            'request' => [
                \Hypervel\Http\Request::class,
                \Symfony\Component\HttpFoundation\Request::class,
            ],
            'router' => [
                \Hypervel\Routing\Router::class,
                \Hypervel\Contracts\Routing\Registrar::class,
                \Hypervel\Contracts\Routing\BindingRegistrar::class,
            ],
            'redirect' => [
                \Hypervel\Routing\Redirector::class,
            ],
            'url' => [
                \Hypervel\Routing\UrlGenerator::class,
                \Hypervel\Contracts\Routing\UrlGenerator::class,
            ],
            'validator' => [
                \Hypervel\Validation\Factory::class,
                \Hypervel\Contracts\Validation\Factory::class,
            ],
            'validation.presence' => [\Hypervel\Validation\DatabasePresenceVerifierInterface::class],
            'view' => [\Hypervel\View\Factory::class, \Hypervel\Contracts\View\Factory::class],
            'blade.compiler' => [\Hypervel\View\Compilers\BladeCompiler::class],
            'session' => [
                \Hypervel\Session\SessionManager::class,
                \Hypervel\Contracts\Session\Factory::class,
            ],
            'session.store' => [\Hypervel\Contracts\Session\Session::class],
            'translator' => [
                \Hypervel\Translation\Translator::class,
                \Hypervel\Contracts\Translation\Translator::class,
            ],
            'translator.loader' => [
                \Hypervel\Translation\FileLoader::class,
                \Hypervel\Contracts\Translation\Loader::class,
            ],
        ] as $key => $aliases) {
            foreach ($aliases as $alias) {
                $this->alias($key, $alias);
            }
        }
    }

    /**
     * Get the application namespace.
     *
     * @throws RuntimeException
     */
    public function getNamespace(): string
    {
        if (isset($this->namespace)) {
            return $this->namespace;
        }

        $composer = json_decode(file_get_contents($this->basePath('composer.json')), true);
        foreach ((array) data_get($composer, 'autoload.psr-4') as $namespace => $path) {
            foreach ((array) $path as $pathChoice) {
                if (realpath($this->path()) === realpath($this->basePath($pathChoice))) {
                    return $this->namespace = $namespace;
                }
            }
        }

        throw new RuntimeException('Unable to detect application namespace.');
    }
}
