<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as ConfigRepositoryContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Foundation\Auth\User;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumGuard;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Server as SwooleServer;

class SanctumServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

    public function testBootContributesLateTokenModelConfiguredGuardModelsAndFrameworkContainers(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => ['enabled' => true],
            ],
            'auth' => [
                'guards' => [
                    'api' => [
                        'driver' => 'sanctum',
                        'provider' => 'users',
                    ],
                    'custom' => [
                        'driver' => 'sanctum',
                        'provider' => 'custom',
                    ],
                    'web' => [
                        'driver' => 'session',
                        'provider' => 'invalid',
                    ],
                    'malformed',
                ],
                'providers' => [
                    'users' => [
                        'driver' => 'eloquent',
                        'model' => SanctumProviderUser::class,
                    ],
                    'custom' => [
                        'driver' => 'custom',
                        'model' => InvalidSanctumProviderModel::class,
                    ],
                    'invalid' => [
                        'driver' => 'eloquent',
                        'model' => InvalidSanctumProviderModel::class,
                    ],
                ],
            ],
        ]);
        $manager = Double::for(CacheManager::class)->strict();
        $resolver = $this->bootAndCaptureResolver($manager, $config);

        Sanctum::usePersonalAccessTokenModel(SanctumProviderToken::class);

        $this->assertSame([
            SanctumProviderToken::class,
            SanctumProviderUser::class,
            EloquentCollection::class,
            Pivot::class,
            MorphPivot::class,
        ], $resolver());
    }

    public function testConsoleStartupUsesDefaultsForOmittedCacheMembers(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => [
                    'enabled' => true,
                ],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $repository = Double::for(CacheRepository::class)->strict();
        $manager = Double::for(CacheManager::class)->strict();
        $manager->expects('allowSerializableClassesUsing');
        $manager->expects('store')->with(null)->returns($repository);
        $validator = Double::for(ModelCacheStoreValidator::class)->strict();
        $validator->expects('validate')
            ->with(Argument::same($repository), 'Sanctum token cache');
        $bootedCallback = null;
        $application = $this->consoleApplication($manager, $config);
        $application->expects('make')
            ->with(ModelCacheStoreValidator::class)
            ->returns($validator);
        $application->expects('booted')
            ->with(Argument::capture($bootedCallback));

        $provider = new SanctumServiceProviderFixture($application->instance());
        $provider->boot();

        $this->assertTrue($provider->bootCalled);
        $this->assertTrue($provider->routesDefined);
        $this->assertTrue($provider->guardConfigured);
        $this->assertTrue($provider->middlewareConfigured);
        $this->assertTrue($provider->publishingRegistered);
        $this->assertTrue($provider->commandsRegistered);
        $this->assertInstanceOf(Closure::class, $bootedCallback);

        $bootedCallback();
    }

    public function testDisabledCachingResolvesNeitherAStoreNorTheValidator(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => [],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $manager = Double::for(CacheManager::class)->strict();
        $resolver = null;
        $manager->expects('allowSerializableClassesUsing')
            ->with(Argument::capture($resolver));
        $manager->expects('store')->never();
        $bootedCallback = null;
        $application = $this->consoleApplication($manager, $config);
        $application->expects('make')->never()->with(ModelCacheStoreValidator::class);
        $application->expects('booted')
            ->with(Argument::capture($bootedCallback));

        (new SanctumServiceProviderFixture($application->instance()))->boot();

        $this->assertInstanceOf(Closure::class, $resolver);
        $this->assertSame([], $resolver());
        $this->assertInstanceOf(Closure::class, $bootedCallback);

        $bootedCallback();
    }

    public function testServerValidationUsesWorkerDependenciesAndRunsForTaskworkers(): void
    {
        $masterConfig = new ConfigRepository([
            'sanctum' => [
                'cache' => ['enabled' => false],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $workerConfig = new ConfigRepository([
            'sanctum' => [
                'cache' => [
                    'enabled' => true,
                    'store' => 'worker',
                    'ttl' => 300,
                    'last_used_at_update_interval' => 300,
                ],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $masterManager = Double::for(CacheManager::class)->strict();
        $masterManager->expects('allowSerializableClassesUsing');
        $masterManager->expects('store')->never();
        $workerRepository = Double::for(CacheRepository::class)->strict();
        $workerManager = Double::for(CacheManager::class)->strict();
        $workerManager->expects('store')->with('worker')->returns($workerRepository);
        $validator = Double::for(ModelCacheStoreValidator::class)->strict();
        $validator->expects('validate')
            ->with(Argument::same($workerRepository), 'Sanctum token cache');
        $listener = null;
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('listen')
            ->with(AfterWorkerStart::class, Argument::capture($listener));
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')
            ->times(2)
            ->with(CacheManager::class)
            ->returns($masterManager, $workerManager);
        $application->expects('make')
            ->times(2)
            ->with(ConfigRepositoryContract::class)
            ->returns($masterConfig, $workerConfig);
        $application->expects('make')->with('events')->returns($events);
        $application->expects('make')
            ->with(ModelCacheStoreValidator::class)
            ->returns($validator);
        $application->expects('runningInConsole')->times(2)->returns(false);
        $application->expects('booted')->never();

        $provider = new SanctumServiceProviderFixture($application->instance());
        $provider->boot();

        $this->assertTrue($provider->bootCalled);
        $this->assertFalse($provider->publishingRegistered);
        $this->assertFalse($provider->commandsRegistered);
        $this->assertInstanceOf(Closure::class, $listener);

        $server = Double::for(SwooleServer::class)->strict();
        // Store validation runs in request workers and taskworkers, unlike Swoole timer registration.
        $server->taskworker = true;
        $listener(new AfterWorkerStart($server, 8));
    }

    public function testSelectedProviderRequiresAnEloquentAuthenticatableModel(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => ['enabled' => true],
            ],
            'auth' => [
                'guards' => [
                    'api' => [
                        'driver' => 'sanctum',
                        'provider' => 'users',
                    ],
                ],
                'providers' => [
                    'users' => [
                        'driver' => 'eloquent',
                        'model' => InvalidSanctumProviderModel::class,
                    ],
                ],
            ],
        ]);
        $manager = Double::for(CacheManager::class)->strict();
        $resolver = $this->bootAndCaptureResolver($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Authentication provider [users] model must be an Eloquent authenticatable class.'
        );

        $resolver();
    }

    public function testDefineRoutesSkipsRegistrationWhenRoutesAreCached(): void
    {
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('routesAreCached')->returns(true);

        (new SanctumServiceProviderFixture($application->instance()))->defineRoutesUsingParent();
    }

    #[DataProvider('invalidRouteConfigurationProvider')]
    public function testDefineRoutesRequiresExactConfigurationTypes(
        string $key,
        mixed $value,
        string $message,
    ): void {
        $config = new ConfigRepository([
            'sanctum' => [
                'routes' => true,
                'prefix' => 'sanctum',
            ],
        ]);
        $config->set($key, $value);

        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('routesAreCached')->returns(false);
        $application->expects('make')
            ->with(ConfigRepositoryContract::class)
            ->returns($config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        (new SanctumServiceProviderFixture($application->instance()))->defineRoutesUsingParent();
    }

    /**
     * Provide invalid Sanctum route configuration.
     */
    public static function invalidRouteConfigurationProvider(): array
    {
        return [
            'routes must be a boolean' => [
                'sanctum.routes',
                'true',
                'Configuration value for key [sanctum.routes] must be a boolean, string given.',
            ],
            'prefix must be a string' => [
                'sanctum.prefix',
                false,
                'Configuration value for key [sanctum.prefix] must be a string, boolean given.',
            ],
        ];
    }

    public function testDefineRoutesRequiresRoutesSetting(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'prefix' => 'sanctum',
            ],
        ]);
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('routesAreCached')->returns(false);
        $application->expects('make')
            ->with(ConfigRepositoryContract::class)
            ->returns($config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Configuration value for key [sanctum.routes] must be a boolean, NULL given.'
        );

        (new SanctumServiceProviderFixture($application->instance()))->defineRoutesUsingParent();
    }

    public function testDefineRoutesRequiresPrefixWhenRoutesAreEnabled(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'routes' => true,
            ],
        ]);
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('routesAreCached')->returns(false);
        $application->expects('make')
            ->with(ConfigRepositoryContract::class)
            ->returns($config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'Configuration value for key [sanctum.prefix] must be a string, NULL given.'
        );

        (new SanctumServiceProviderFixture($application->instance()))->defineRoutesUsingParent();
    }

    // REMOVED: Hypervel's Middleware::statefulApi() owns middleware priority before kernel construction.

    public function testCreateGuardRemainsAProtectedExtensionPoint(): void
    {
        $application = Double::for(Application::class, override: true)->strict();
        $container = Double::for(Container::class, override: true)->strict();
        $config = new ConfigRepository([
            'sanctum' => [
                'expiration' => null,
                'last_used_at' => true,
            ],
        ]);
        $container->expects('bound')->with('events')->returns(false);
        $container->expects('make')->times(2)->with('config')->returns($config);
        $userProvider = Double::for(UserProvider::class)->strict();
        $authManager = Double::for(AuthManager::class)->strict();
        $authManager->expects('createUserProvider')->with('users')->returns($userProvider);

        $guard = (new SanctumServiceProviderFixture($application->instance()))->createGuardUsingParent(
            $authManager,
            $container->instance(),
            'sanctum',
            [
                'provider' => 'users',
                'session_guards' => ['web'],
            ],
        );

        $this->assertInstanceOf(SanctumGuard::class, $guard);
    }

    public function testConfiguredGuardResolutionUsesTheProtectedFactoryExtensionPoint(): void
    {
        $afterResolving = null;
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('afterResolving')
            ->with(AuthManager::class, Argument::capture($afterResolving));
        $application->expects('resolved')->with(AuthManager::class)->returns(false);

        $provider = new ResolvingSanctumServiceProviderFixture($application->instance());
        $provider->guard = Double::for(SanctumGuard::class)->strict();
        $provider->configureGuardUsingParent();

        $this->assertInstanceOf(Closure::class, $afterResolving);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('config')->returns(new ConfigRepository([
            'auth' => [
                'guards' => [
                    'sanctum' => ['driver' => 'sanctum'],
                ],
            ],
        ]));
        $authManager = new AuthManager($container->instance());

        $afterResolving($authManager);

        $this->assertSame($provider->guard, $authManager->guard('sanctum'));
        $this->assertTrue($provider->createGuardCalled);
    }

    public function testConfiguredStoreMustBeAStringOrNull(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => [
                    'enabled' => true,
                    'store' => [],
                ],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $manager = Double::for(CacheManager::class)->strict();
        $manager->expects('allowSerializableClassesUsing');
        $manager->expects('store')->never();
        $bootedCallback = null;
        $application = $this->consoleApplication($manager, $config);
        $application->expects('booted')
            ->with(Argument::capture($bootedCallback));

        (new SanctumServiceProviderFixture($application->instance()))->boot();

        $this->assertInstanceOf(Closure::class, $bootedCallback);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Sanctum cache store must be a string or null.');

        $bootedCallback();
    }

    #[DataProvider('invalidCacheTtlProvider')]
    public function testCachingRequiresPositiveIntegerTtl(array $cache, string $message): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => [
                    'enabled' => true,
                    ...$cache,
                ],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $manager = Double::for(CacheManager::class)->strict();
        $manager->expects('store')->never();
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $startup();
    }

    public static function invalidCacheTtlProvider(): iterable
    {
        yield 'null' => [['ttl' => null], 'Configuration value for key [sanctum.cache.ttl] must be an integer, NULL given.'];
        yield 'zero' => [['ttl' => 0], 'Sanctum cache TTL must be a positive integer.'];
        yield 'negative' => [['ttl' => -1], 'Sanctum cache TTL must be a positive integer.'];
        yield 'numeric string' => [['ttl' => '300'], 'Configuration value for key [sanctum.cache.ttl] must be an integer, string given.'];
        yield 'float' => [['ttl' => 1.5], 'Configuration value for key [sanctum.cache.ttl] must be an integer, double given.'];
        yield 'boolean' => [['ttl' => true], 'Configuration value for key [sanctum.cache.ttl] must be an integer, boolean given.'];
    }

    #[DataProvider('invalidLastUsedUpdateIntervalProvider')]
    public function testCachingRequiresNonNegativeIntegerLastUsedUpdateInterval(array $cache, string $message): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => [
                    'enabled' => true,
                    'ttl' => 300,
                    ...$cache,
                ],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $manager = Double::for(CacheManager::class)->strict();
        $manager->expects('store')->never();
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs($message);

        $startup();
    }

    public static function invalidLastUsedUpdateIntervalProvider(): iterable
    {
        yield 'null' => [['last_used_at_update_interval' => null], 'Configuration value for key [sanctum.cache.last_used_at_update_interval] must be an integer, NULL given.'];
        yield 'negative' => [['last_used_at_update_interval' => -1], 'Sanctum cache last_used_at_update_interval must be a non-negative integer.'];
        yield 'numeric string' => [['last_used_at_update_interval' => '300'], 'Configuration value for key [sanctum.cache.last_used_at_update_interval] must be an integer, string given.'];
        yield 'float' => [['last_used_at_update_interval' => 1.5], 'Configuration value for key [sanctum.cache.last_used_at_update_interval] must be an integer, double given.'];
        yield 'boolean' => [['last_used_at_update_interval' => true], 'Configuration value for key [sanctum.cache.last_used_at_update_interval] must be an integer, boolean given.'];
    }

    /**
     * Create a console application double for provider boot.
     */
    private function consoleApplication(
        CacheManager $manager,
        ConfigRepositoryContract $config,
    ): DoubleInterface {
        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')
            ->with(CacheManager::class)
            ->returns($manager);
        $application->expects('make')
            ->with(ConfigRepositoryContract::class)
            ->returns($config);
        $application->expects('runningInConsole')->times(2)->returns(true);

        return $application;
    }

    /**
     * Boot the provider and capture its console startup validation callback.
     */
    private function bootAndCaptureStartupValidation(
        CacheManager&DoubleInterface $manager,
        ConfigRepositoryContract $config,
    ): Closure {
        $manager->expects('allowSerializableClassesUsing');
        $startup = null;
        $application = $this->consoleApplication($manager, $config);
        $application->expects('booted')
            ->with(Argument::capture($startup));

        (new SanctumServiceProviderFixture($application->instance()))->boot();

        $this->assertInstanceOf(Closure::class, $startup);

        return $startup;
    }

    /**
     * Boot the provider and capture its class resolver.
     */
    private function bootAndCaptureResolver(
        CacheManager&DoubleInterface $manager,
        ConfigRepositoryContract $config,
    ): Closure {
        $resolver = null;
        $manager->expects('allowSerializableClassesUsing')
            ->with(Argument::capture($resolver));
        $application = $this->consoleApplication($manager, $config);
        $application->expects('booted');

        (new SanctumServiceProviderFixture($application->instance()))->boot();

        $this->assertInstanceOf(Closure::class, $resolver);

        return $resolver;
    }
}

class SanctumServiceProviderFixture extends SanctumServiceProvider
{
    public bool $bootCalled = false;

    public bool $routesDefined = false;

    public bool $guardConfigured = false;

    public bool $middlewareConfigured = false;

    public bool $publishingRegistered = false;

    public bool $commandsRegistered = false;

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->bootCalled = true;

        parent::boot();
    }

    /**
     * Define the Sanctum routes.
     */
    protected function defineRoutes(): void
    {
        $this->routesDefined = true;
    }

    /**
     * Invoke the parent route definition.
     */
    public function defineRoutesUsingParent(): void
    {
        parent::defineRoutes();
    }

    /**
     * Configure the Sanctum authentication guard.
     */
    protected function configureGuard(): void
    {
        $this->guardConfigured = true;
    }

    /**
     * Configure Sanctum's middleware behavior.
     */
    protected function configureMiddleware(): void
    {
        $this->middlewareConfigured = true;
    }

    /**
     * Invoke the parent guard factory.
     */
    public function createGuardUsingParent(
        AuthManager $authManager,
        Container $app,
        string $name,
        array $config,
    ): SanctumGuard {
        return parent::createGuard($authManager, $app, $name, $config);
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishingRegistered = true;
    }

    /**
     * Register the console commands for the package.
     */
    protected function registerCommands(): void
    {
        $this->commandsRegistered = true;
    }
}

class ResolvingSanctumServiceProviderFixture extends SanctumServiceProvider
{
    public SanctumGuard $guard;

    public bool $createGuardCalled = false;

    /**
     * Invoke the parent guard configuration.
     */
    public function configureGuardUsingParent(): void
    {
        parent::configureGuard();
    }

    /**
     * Create the test guard instance.
     */
    protected function createGuard(
        AuthManager $authManager,
        Container $app,
        string $name,
        array $config,
    ): SanctumGuard {
        $this->createGuardCalled = true;

        return $this->guard;
    }
}

class SanctumProviderUser extends User
{
}

class SanctumProviderToken extends PersonalAccessToken
{
}

class InvalidSanctumProviderModel
{
}
