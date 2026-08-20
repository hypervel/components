<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Auth\AuthServiceProvider;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as ConfigRepositoryContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Foundation\Auth\User;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Server as SwooleServer;

class AuthServiceProviderTest extends TestCase
{
    public function testBootContributesEnabledConfiguredEloquentModelsAndFrameworkContainers(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $this->cachedProvider(AuthProviderUser::class),
                    'admins' => $this->cachedProvider(AuthProviderAdmin::class, enabled: 'yes', store: 'redis'),
                    'disabled' => $this->cachedProvider(AuthProviderUser::class, enabled: false),
                    'database' => [
                        'driver' => 'database',
                        'model' => InvalidAuthProviderModel::class,
                        'cache' => ['enabled' => true],
                    ],
                    'malformed',
                ],
            ],
        ]);
        $resolver = null;
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$resolver): bool {
                $resolver = $callback;

                return $callback instanceof Closure;
            }))
            ->andReturnSelf();
        $application = $this->consoleApplication();
        $application->shouldReceive('booted')->once();

        (new AuthServiceProvider($application))->boot(
            $manager,
            $config,
            m::mock(GateContract::class),
        );

        $this->assertInstanceOf(Closure::class, $resolver);
        $this->assertTrue(EloquentBuilder::hasGlobalMacro('whereCan'));
        $this->assertTrue(EloquentBuilder::hasGlobalMacro('withCan'));
        $this->assertSame([
            AuthProviderUser::class,
            AuthProviderAdmin::class,
            EloquentCollection::class,
            Pivot::class,
            MorphPivot::class,
        ], $resolver());
    }

    public function testConsoleStartupValidatesEveryEnabledProviderUsingCapturedDependencies(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    0 => $this->cachedProvider(AuthProviderUser::class),
                    '' => $this->cachedProvider(AuthProviderAdmin::class, store: 'redis'),
                ],
            ],
        ]);
        $defaultRepository = m::mock(CacheRepository::class);
        $redisRepository = m::mock(CacheRepository::class);
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $manager->shouldReceive('store')->once()->with(null)->andReturn($defaultRepository);
        $manager->shouldReceive('store')->once()->with('redis')->andReturn($redisRepository);
        $validator = m::mock(ModelCacheStoreValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->with($defaultRepository, 'Auth user provider [0]');
        $validator->shouldReceive('validate')
            ->once()
            ->with($redisRepository, 'Auth user provider []');
        $bootedCallback = null;
        $application = $this->consoleApplication();
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

        (new AuthServiceProvider($application))->boot(
            $manager,
            $config,
            m::mock(GateContract::class),
        );

        $this->assertInstanceOf(Closure::class, $bootedCallback);
        $bootedCallback();
    }

    public function testDisabledProvidersResolveNeitherAStoreNorTheValidator(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $this->cachedProvider(AuthProviderUser::class, enabled: false),
                    'custom' => [
                        'driver' => 'custom',
                        'cache' => ['enabled' => true],
                    ],
                ],
            ],
        ]);
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $manager->shouldNotReceive('store');
        $bootedCallback = null;
        $application = $this->consoleApplication();
        $application->shouldNotReceive('make')->with(ModelCacheStoreValidator::class);
        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$bootedCallback): bool {
                $bootedCallback = $callback;

                return $callback instanceof Closure;
            }));

        (new AuthServiceProvider($application))->boot(
            $manager,
            $config,
            m::mock(GateContract::class),
        );

        $this->assertSame([], $this->capturedResolver($manager)());
        $this->assertInstanceOf(Closure::class, $bootedCallback);
        $bootedCallback();
    }

    public function testServerValidationUsesWorkerDependenciesAndRunsForTaskworkers(): void
    {
        $masterConfig = new ConfigRepository([
            'auth' => ['providers' => []],
        ]);
        $workerConfig = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $this->cachedProvider(AuthProviderUser::class, store: 'worker'),
                ],
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
            ->with($workerRepository, 'Auth user provider [users]');
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
            ->once()
            ->with(CacheManager::class)
            ->andReturn($workerManager);
        $application->shouldReceive('make')
            ->once()
            ->with(ConfigRepositoryContract::class)
            ->andReturn($workerConfig);
        $application->shouldReceive('make')->once()->with('events')->andReturn($events);
        $application->shouldReceive('make')
            ->once()
            ->with(ModelCacheStoreValidator::class)
            ->andReturn($validator);
        $application->shouldReceive('runningInConsole')->once()->andReturnFalse();
        $application->shouldNotReceive('booted');

        (new AuthServiceProvider($application))->boot(
            $masterManager,
            $masterConfig,
            m::mock(GateContract::class),
        );

        $this->assertInstanceOf(Closure::class, $listener);
        $server = m::mock(SwooleServer::class);
        // Store validation runs in request workers and taskworkers, unlike Swoole timer registration.
        $server->taskworker = true;
        $listener(new AfterWorkerStart($server, 8));
    }

    public function testBootReplacesQueryBuilderMacrosWithFreshGate(): void
    {
        $config = new ConfigRepository([
            'auth' => ['providers' => []],
        ]);
        $firstManager = m::mock(CacheManager::class);
        $firstManager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $secondManager = m::mock(CacheManager::class);
        $secondManager->shouldReceive('allowSerializableClassesUsing')->once()->andReturnSelf();
        $firstGate = m::mock(GateContract::class);
        $firstGate->shouldNotReceive('scope');
        $secondGate = m::mock(GateContract::class);
        $secondGate->shouldReceive('scope')
            ->once()
            ->with('edit', m::type(EloquentBuilder::class))
            ->andReturnUsing(static fn (mixed $ability, EloquentBuilder $query): EloquentBuilder => $query);
        $firstApplication = $this->consoleApplication();
        $firstApplication->shouldReceive('booted')->once();
        $secondApplication = $this->consoleApplication();
        $secondApplication->shouldReceive('booted')->once();

        (new AuthServiceProvider($firstApplication))->boot($firstManager, $config, $firstGate);
        (new AuthServiceProvider($secondApplication))->boot($secondManager, $config, $secondGate);

        $query = new EloquentBuilder(m::mock(QueryBuilder::class));

        $this->assertSame($query, $query->whereCan('edit'));
    }

    public function testStartupRequiresAnEloquentAuthenticatableModel(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $this->cachedProvider(InvalidAuthProviderModel::class),
                ],
            ],
        ]);
        $manager = m::mock(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Authentication provider [users] model must be an Eloquent authenticatable class.');

        $startup();
    }

    public function testStartupRequiresAStringOrNullStore(): void
    {
        $provider = $this->cachedProvider(AuthProviderUser::class);
        $provider['cache']['store'] = [];
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $provider,
                ],
            ],
        ]);
        $manager = m::mock(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Authentication provider [users] cache store must be a string or null.');

        $startup();
    }

    #[DataProvider('invalidCacheTtlProvider')]
    public function testStartupRequiresPositiveIntegerCacheTtl(mixed $ttl): void
    {
        $provider = $this->cachedProvider(AuthProviderUser::class);
        $provider['cache']['ttl'] = $ttl;
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $provider,
                ],
            ],
        ]);
        $manager = m::mock(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Authentication provider [users] cache TTL must be a positive integer.');

        $startup();
    }

    public static function invalidCacheTtlProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'numeric string' => ['300'];
        yield 'non-numeric string' => ['five minutes'];
        yield 'float' => [1.5];
        yield 'boolean' => [true];
    }

    #[DataProvider('invalidCacheTagsProvider')]
    public function testStartupRequiresAnArrayOfStringsOrNullCacheTags(mixed $tags): void
    {
        $provider = $this->cachedProvider(AuthProviderUser::class);
        $provider['cache']['tags'] = $tags;
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $provider,
                ],
            ],
        ]);
        $manager = m::mock(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Authentication provider [users] cache tags must be an array of strings or null.');

        $startup();
    }

    public static function invalidCacheTagsProvider(): iterable
    {
        yield 'string' => ['auth_users'];
        yield 'integer element' => [[123]];
        yield 'null element' => [[null]];
        yield 'mixed elements' => [['auth_users', true]];
    }

    public function testStartupValidatesTagSupportForTaggedProvider(): void
    {
        $provider = $this->cachedProvider(AuthProviderUser::class, store: 'redis');
        $provider['cache']['tags'] = ['auth_users'];
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'users' => $provider,
                ],
            ],
        ]);
        $repository = m::mock(CacheRepository::class);
        $manager = m::mock(CacheManager::class);
        $manager->shouldReceive('store')->once()->with('redis')->andReturn($repository);
        $validator = m::mock(ModelCacheStoreValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->with($repository, 'Auth user provider [users]');
        $validator->shouldReceive('validateAnyModeTags')
            ->once()
            ->with($repository, 'Auth user provider [users]')
            ->andThrow(new InvalidArgumentException('TagMode::Any is required.'));
        $startup = $this->bootAndCaptureStartupValidation($manager, $config, $validator);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TagMode::Any is required.');

        $startup();
    }

    /**
     * Create a cache-enabled Eloquent provider configuration.
     *
     * @param class-string $model
     * @return array<string, mixed>
     */
    private function cachedProvider(
        string $model,
        bool|string $enabled = true,
        ?string $store = null,
    ): array {
        return [
            'driver' => 'eloquent',
            'model' => $model,
            'cache' => [
                'enabled' => $enabled,
                'store' => $store,
            ],
        ];
    }

    /**
     * Create a console application double for provider boot.
     */
    private function consoleApplication(): Application|m\MockInterface
    {
        $application = m::mock(Application::class);
        $application->shouldReceive('runningInConsole')->once()->andReturnTrue();

        return $application;
    }

    /**
     * Boot the provider and capture its console startup validation callback.
     */
    private function bootAndCaptureStartupValidation(
        CacheManager|m\MockInterface $manager,
        ConfigRepositoryContract $config,
        (ModelCacheStoreValidator&m\MockInterface)|null $validator = null,
    ): Closure {
        $manager->shouldReceive('allowSerializableClassesUsing')
            ->once()
            ->andReturnSelf();
        $startup = null;
        $application = $this->consoleApplication();

        if ($validator !== null) {
            $application->shouldReceive('make')
                ->once()
                ->with(ModelCacheStoreValidator::class)
                ->andReturn($validator);
        }

        $application->shouldReceive('booted')
            ->once()
            ->with(m::on(function (mixed $callback) use (&$startup): bool {
                $startup = $callback;

                return $callback instanceof Closure;
            }));

        (new AuthServiceProvider($application))->boot(
            $manager,
            $config,
            m::mock(GateContract::class),
        );

        $this->assertInstanceOf(Closure::class, $startup);

        return $startup;
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

class AuthProviderUser extends User
{
}

class AuthProviderAdmin extends User
{
}

class InvalidAuthProviderModel
{
}
