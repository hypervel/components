<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Closure;
use Hypervel\Config\ProviderConfig;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Bootstrap\RegisterProviders;
use Hypervel\Foundation\Events\LocaleUpdated;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Environment;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Traits\Macroable;
use ReflectionClass;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

class Application extends Container implements ApplicationContract
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
     * Indicates if the application has been bootstrapped before.
     */
    protected bool $hasBeenBootstrapped = false;

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
        $this->registerCoreContainerAliases();
        $this->registerConfigProviderDependencies();
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
     * Register ConfigProvider dependencies as singletons.
     *
     * This is a temporary bridge that replaces the old DefinitionSourceFactory system
     * which pre-loaded ConfigProvider dependencies into the Hyperf container before
     * the Application existed. Once all packages are migrated to ServiceProviders,
     * this method can be removed.
     */
    protected function registerConfigProviderDependencies(): void
    {
        if (! class_exists(ProviderConfig::class) || ! defined('BASE_PATH')) {
            return;
        }

        $dependencies = ProviderConfig::load()['dependencies'] ?? [];

        $paths = [
            $this->basePath('config/autoload/dependencies.php'),
            $this->basePath('config/dependencies.php'),
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $definitions = include $path;
                $dependencies = array_replace($dependencies, $definitions ?? []);
            }
        }

        foreach ($dependencies as $abstract => $concrete) {
            // Resolve alias chain so bindings are stored under the canonical
            // abstract, not under an alias key that getAlias() would skip.
            $abstract = $this->getAlias($abstract);

            if ($concrete instanceof Closure) {
                $this->singleton($abstract, $concrete);
            } elseif (is_string($concrete) && class_exists($concrete) && method_exists($concrete, '__invoke')) {
                // Hyperf factory pattern: classes with __invoke() are factories
                // that produce the actual service when called.
                $this->singleton($abstract, function ($app) use ($concrete) {
                    return $app->make($concrete)($app);
                });
            } elseif (is_string($concrete)) {
                // Use a closure to build the concrete class directly, bypassing
                // the alias chain. Without this, string concretes that are also
                // aliased back to the abstract create infinite resolution cycles.
                $this->singleton($abstract, fn ($app) => $app->build($concrete));
            }
        }
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
        return $this->joinPaths($this->basePath('storage'), $path);
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
            return $this->get(Environment::class)->is(...$environments);
        }

        return $this->detectEnvironment();
    }

    /**
     * Determine if the application is in the local environment.
     */
    public function isLocal(): bool
    {
        return $this->get(Environment::class)->is('local');
    }

    /**
     * Determine if the application is in the production environment.
     */
    public function isProduction(): bool
    {
        return $this->get(Environment::class)->is('production');
    }

    /**
     * Detect the application's current environment.
     */
    public function detectEnvironment(): string
    {
        return $this->get(Environment::class)->get();
    }

    /**
     * Determine if the application is running unit tests.
     */
    public function runningUnitTests(): bool
    {
        return $this->get(Environment::class)->is('testing');
    }

    /**
     * Determine if the application is running with debug mode enabled.
     */
    public function hasDebugModeEnabled(): bool
    {
        return $this->get(Environment::class)->isDebug();
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
    public function abort(int $code, string $message = '', array $headers = []): void
    {
        if ($code == 404) {
            throw new NotFoundHttpException($message, 0, null, $headers);
        }

        throw new HttpException($code, $message, 0, null, $headers);
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
            \Hypervel\Contracts\Config\Repository::class => [
                'config',
                \Hypervel\Config\Repository::class,
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
            \Psr\EventDispatcher\EventDispatcherInterface::class => [
                'events',
                \Hypervel\Contracts\Event\Dispatcher::class,
            ],
            \Psr\EventDispatcher\ListenerProviderInterface::class => [
                \Hypervel\Event\Contracts\ListenerProvider::class,
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
            'redis' => [\Hypervel\Redis\Redis::class],
            'request' => [
                \Psr\Http\Message\ServerRequestInterface::class,
                \Hypervel\HttpServer\Contracts\RequestInterface::class,
                \Hypervel\HttpServer\Request::class,
                \Hypervel\Contracts\Http\Request::class,
                \Hypervel\Http\Request::class,
            ],
            'response' => [
                \Hypervel\Contracts\Http\Response::class,
                \Hypervel\HttpServer\Contracts\ResponseInterface::class,
                \Hypervel\HttpServer\Response::class,
                \Hypervel\Http\Response::class,
            ],
            'router' => [\Hypervel\Router\Router::class],
            'url' => [
                \Hypervel\Contracts\Router\UrlGenerator::class,
                \Hypervel\Router\UrlGenerator::class,
            ],
            \Hypervel\Contracts\Validation\Factory::class => ['validator'],
            \Hypervel\Validation\DatabasePresenceVerifierInterface::class => ['validation.presence'],
            \Hypervel\View\Contracts\Factory::class => ['view'],
            \Hypervel\View\Compilers\CompilerInterface::class => ['blade.compiler'],
            'session' => [
                \Hypervel\Session\SessionManager::class,
                \Hypervel\Contracts\Session\Factory::class,
            ],
            'session.store' => [\Hypervel\Contracts\Session\Session::class],
            \Hypervel\Contracts\Translation\Translator::class => ['translator'],
            \Hypervel\Contracts\Translation\Loader::class => ['translator.loader'],
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
