<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Closure;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as ConfigRepositoryContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Foundation\Auth\User;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
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
        $this->assertTrue($provider->sanctumGuardRegistered);
        $this->assertTrue($provider->sessionCookiesConfigured);
        $this->assertTrue($provider->routesRegistered);
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

    public bool $sanctumGuardRegistered = false;

    public bool $sessionCookiesConfigured = false;

    public bool $routesRegistered = false;

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
     * Register the Sanctum authentication guard.
     */
    protected function registerSanctumGuard(): void
    {
        $this->sanctumGuardRegistered = true;
    }

    /**
     * Configure session cookies for stateful frontend requests.
     */
    protected function configureSessionCookies(): void
    {
        $this->sessionCookiesConfigured = true;
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        $this->routesRegistered = true;
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

class SanctumProviderUser extends User
{
}

class SanctumProviderToken extends PersonalAccessToken
{
}

class InvalidSanctumProviderModel
{
}
