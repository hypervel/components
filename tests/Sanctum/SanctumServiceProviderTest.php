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
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Server as SwooleServer;

class SanctumServiceProviderTest extends TestCase
{
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
        $manager = m::mock(CacheManager::class);
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

    public function testConsoleStartupValidatesTheConfiguredStoreUsingCapturedDependencies(): void
    {
        $config = new ConfigRepository([
            'sanctum' => [
                'cache' => [
                    'enabled' => true,
                    'store' => '',
                    'ttl' => 300,
                    'last_used_at_update_interval' => 0,
                ],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $repository = m::mock(CacheRepository::class);
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $manager->shouldReceive('store')->once()->with(null)->andReturn($repository);
        $validator = m::mock(ModelCacheStoreValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->with($repository, 'Sanctum token cache');
        $bootedCallback = null;
        $application = $this->consoleApplication($manager, $config);
        $application->shouldReceive('make')
            ->once()
            ->with(ModelCacheStoreValidator::class)
            ->andReturn($validator);
        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$bootedCallback): bool {
                $bootedCallback = $callback;

                return $callback instanceof Closure;
            }));

        $provider = new SanctumServiceProviderFixture($application);
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
                'cache' => ['enabled' => false],
            ],
            'auth' => [
                'guards' => [],
            ],
        ]);
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $manager->shouldNotReceive('store');
        $bootedCallback = null;
        $application = $this->consoleApplication($manager, $config);
        $application->shouldNotReceive('make')->with(ModelCacheStoreValidator::class);
        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$bootedCallback): bool {
                $bootedCallback = $callback;

                return $callback instanceof Closure;
            }));

        (new SanctumServiceProviderFixture($application))->boot();

        $this->assertSame([], $this->capturedResolver($manager)());
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
        $masterManager = m::mock(CacheManager::class);
        $masterManager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $masterManager->shouldNotReceive('store');
        $workerRepository = m::mock(CacheRepository::class);
        $workerManager = m::mock(CacheManager::class);
        $workerManager->shouldReceive('store')->once()->with('worker')->andReturn($workerRepository);
        $validator = m::mock(ModelCacheStoreValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->with($workerRepository, 'Sanctum token cache');
        $listener = null;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen')
            ->once()
            ->with(AfterWorkerStart::class, m::on(function (mixed $callback) use (&$listener): bool {
                $listener = $callback;

                return $callback instanceof Closure;
            }));
        $application = m::mock(Application::class);
        $application->shouldReceive('make')
            ->twice()
            ->with(CacheManager::class)
            ->andReturn($masterManager, $workerManager);
        $application->shouldReceive('make')
            ->twice()
            ->with(ConfigRepositoryContract::class)
            ->andReturn($masterConfig, $workerConfig);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('make')
            ->once()
            ->with(ModelCacheStoreValidator::class)
            ->andReturn($validator);
        $application->shouldReceive('runningInConsole')->twice()->andReturnFalse();
        $application->shouldNotReceive('booted');

        $provider = new SanctumServiceProviderFixture($application);
        $provider->boot();

        $this->assertTrue($provider->bootCalled);
        $this->assertFalse($provider->publishingRegistered);
        $this->assertFalse($provider->commandsRegistered);
        $this->assertInstanceOf(Closure::class, $listener);

        $server = m::mock(SwooleServer::class);
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
        $manager = m::mock(CacheManager::class);
        $resolver = $this->bootAndCaptureResolver($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Authentication provider [users] model must be an Eloquent authenticatable class.'
        );

        $resolver();
    }

    public function testDefineRoutesSkipsRegistrationWhenRoutesAreCached(): void
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('routesAreCached')->once()->andReturnTrue();

        (new SanctumServiceProviderFixture($application))->defineRoutesUsingParent();

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidRouteConfigurationProvider')]
    public function testDefineRoutesRequiresExactConfigurationTypes(
        string $key,
        mixed $value,
        string $message,
    ): void {
        $application = m::mock(Application::class);
        $application->shouldReceive('routesAreCached')->once()->andReturnFalse();
        $application->shouldReceive('make')
            ->once()
            ->with(ConfigRepositoryContract::class)
            ->andReturn(new ConfigRepository([$key => $value]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new SanctumServiceProviderFixture($application))->defineRoutesUsingParent();
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

    // REMOVED: Hypervel's Middleware::statefulApi() owns middleware priority before kernel construction.

    public function testCreateGuardRemainsAProtectedExtensionPoint(): void
    {
        $application = m::mock(Application::class);
        $container = m::mock(Container::class);
        $config = new ConfigRepository([
            'sanctum' => [
                'expiration' => null,
                'last_used_at' => true,
            ],
        ]);
        $container->shouldReceive('bound')->once()->with('events')->andReturnFalse();
        $container->shouldReceive('make')->twice()->with('config')->andReturn($config);
        $userProvider = m::mock(UserProvider::class);
        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('createUserProvider')->once()->with('users')->andReturn($userProvider);

        $guard = (new SanctumServiceProviderFixture($application))->createGuardUsingParent(
            $authManager,
            $container,
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
        $application = m::mock(Application::class);
        $application->shouldReceive('afterResolving')
            ->once()
            ->with(AuthManager::class, m::on(function (mixed $callback) use (&$afterResolving): bool {
                $afterResolving = $callback;

                return $callback instanceof Closure;
            }));
        $application->shouldReceive('resolved')->once()->with(AuthManager::class)->andReturnFalse();

        $provider = new ResolvingSanctumServiceProviderFixture($application);
        $provider->guard = m::mock(SanctumGuard::class);
        $provider->configureGuardUsingParent();

        $this->assertInstanceOf(Closure::class, $afterResolving);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn(new ConfigRepository([
            'auth' => [
                'guards' => [
                    'sanctum' => ['driver' => 'sanctum'],
                ],
            ],
        ]));
        $authManager = new AuthManager($container);

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
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $manager->shouldNotReceive('store');
        $bootedCallback = null;
        $application = $this->consoleApplication($manager, $config);
        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$bootedCallback): bool {
                $bootedCallback = $callback;

                return $callback instanceof Closure;
            }));

        (new SanctumServiceProviderFixture($application))->boot();

        $this->assertInstanceOf(Closure::class, $bootedCallback);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sanctum cache store must be a string or null.');

        $bootedCallback();
    }

    #[DataProvider('invalidCacheTtlProvider')]
    public function testCachingRequiresPositiveIntegerTtl(array $cache): void
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
        $manager = m::mock(CacheManager::class);
        $manager->shouldNotReceive('store');
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sanctum cache TTL must be a positive integer.');

        $startup();
    }

    public static function invalidCacheTtlProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['ttl' => null]];
        yield 'zero' => [['ttl' => 0]];
        yield 'negative' => [['ttl' => -1]];
        yield 'numeric string' => [['ttl' => '300']];
        yield 'float' => [['ttl' => 1.5]];
        yield 'boolean' => [['ttl' => true]];
    }

    #[DataProvider('invalidLastUsedUpdateIntervalProvider')]
    public function testCachingRequiresNonNegativeIntegerLastUsedUpdateInterval(array $cache): void
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
        $manager = m::mock(CacheManager::class);
        $manager->shouldNotReceive('store');
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Sanctum cache last_used_at_update_interval must be a non-negative integer.'
        );

        $startup();
    }

    public static function invalidLastUsedUpdateIntervalProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['last_used_at_update_interval' => null]];
        yield 'negative' => [['last_used_at_update_interval' => -1]];
        yield 'numeric string' => [['last_used_at_update_interval' => '300']];
        yield 'float' => [['last_used_at_update_interval' => 1.5]];
        yield 'boolean' => [['last_used_at_update_interval' => true]];
    }

    /**
     * Create a console application double for provider boot.
     */
    private function consoleApplication(
        CacheManager $manager,
        ConfigRepositoryContract $config,
    ): Application|m\MockInterface {
        $application = m::mock(Application::class);
        $application->shouldReceive('make')
            ->once()
            ->with(CacheManager::class)
            ->andReturn($manager);
        $application->shouldReceive('make')
            ->once()
            ->with(ConfigRepositoryContract::class)
            ->andReturn($config);
        $application->shouldReceive('runningInConsole')->twice()->andReturnTrue();

        return $application;
    }

    /**
     * Boot the provider and capture its console startup validation callback.
     */
    private function bootAndCaptureStartupValidation(
        CacheManager|m\MockInterface $manager,
        ConfigRepositoryContract $config,
    ): Closure {
        $manager->shouldReceive('allowSerializableClassesUsing')
            ->once()
            ->andReturnSelf();
        $startup = null;
        $application = $this->consoleApplication($manager, $config);
        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$startup): bool {
                $startup = $callback;

                return $callback instanceof Closure;
            }));

        (new SanctumServiceProviderFixture($application))->boot();

        $this->assertInstanceOf(Closure::class, $startup);

        return $startup;
    }

    /**
     * Boot the provider and capture its class resolver.
     */
    private function bootAndCaptureResolver(
        CacheManager|m\MockInterface $manager,
        ConfigRepositoryContract $config,
    ): Closure {
        $resolver = null;
        $manager->shouldReceive('allowSerializableClassesUsing')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$resolver): bool {
                $resolver = $callback;

                return $callback instanceof Closure;
            }))
            ->andReturnSelf();
        $application = $this->consoleApplication($manager, $config);
        $application->shouldReceive('booted')->once();

        (new SanctumServiceProviderFixture($application))->boot();

        $this->assertInstanceOf(Closure::class, $resolver);

        return $resolver;
    }

    /**
     * Capture the registered class resolver from a manager mock.
     */
    private function capturedResolver(CacheManager|m\MockInterface $manager): Closure
    {
        $resolver = null;
        $manager->shouldHaveReceived('allowSerializableClassesUsing')
            ->with(m::on(function (mixed $callback) use (&$resolver): bool {
                $resolver = $callback;

                return $callback instanceof Closure;
            }))
            ->once();

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
