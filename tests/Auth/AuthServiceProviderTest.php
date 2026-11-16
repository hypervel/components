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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Server as SwooleServer;

class AuthServiceProviderTest extends TestCase
{
    use VerifiesDoubles;

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
                        'table' => 'users',
                    ],
                    'malformed',
                ],
            ],
        ]);
        $resolver = null;
        $manager = Double::for(CacheManager::class);
        $manager->expects('allowSerializableClassesUsing')
            ->with(Argument::capture($resolver));
        $application = $this->consoleApplication();
        $application->expects('booted');

        (new AuthServiceProvider($application->instance()))->boot(
            $manager,
            $config,
            Double::for(GateContract::class, override: true)->instance(),
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
                    0 => [
                        'driver' => 'eloquent',
                        'model' => AuthProviderUser::class,
                        'cache' => ['enabled' => true],
                    ],
                    '' => $this->cachedProvider(AuthProviderAdmin::class, store: 'redis'),
                ],
            ],
        ]);
        $defaultRepository = Double::for(CacheRepository::class);
        $redisRepository = Double::for(CacheRepository::class);
        $manager = Double::for(CacheManager::class);
        $manager->expects('allowSerializableClassesUsing');
        $manager->expects('store')->with(null)->returns($defaultRepository);
        $manager->expects('store')->with('redis')->returns($redisRepository);
        $validator = Double::for(ModelCacheStoreValidator::class);
        $validator->expects('validate')
            ->with(Argument::same($defaultRepository), 'Auth user provider [0]');
        $validator->expects('validate')
            ->with(Argument::same($redisRepository), 'Auth user provider []');
        $bootedCallback = null;
        $application = $this->consoleApplication();
        $application->expects('make')
            ->with(ModelCacheStoreValidator::class)
            ->returns($validator);
        $application->expects('booted')
            ->with(Argument::capture($bootedCallback));

        (new AuthServiceProvider($application->instance()))->boot(
            $manager,
            $config,
            Double::for(GateContract::class, override: true)->instance(),
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
                    'uncached' => [
                        'driver' => 'eloquent',
                        'model' => AuthProviderUser::class,
                    ],
                    'null-cache' => [
                        'driver' => 'eloquent',
                        'model' => AuthProviderUser::class,
                        'cache' => null,
                    ],
                    'custom' => [
                        'driver' => 'custom',
                    ],
                ],
            ],
        ]);
        $manager = Double::for(CacheManager::class);
        $resolver = null;
        $manager->expects('allowSerializableClassesUsing')
            ->with(Argument::capture($resolver));
        $manager->expects('store')->never();
        $bootedCallback = null;
        $application = $this->consoleApplication();
        $application->expects('make')->never()->with(ModelCacheStoreValidator::class);
        $application->expects('booted')
            ->with(Argument::capture($bootedCallback));

        (new AuthServiceProvider($application->instance()))->boot(
            $manager,
            $config,
            Double::for(GateContract::class, override: true)->instance(),
        );

        $this->assertSame([], $resolver());
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
        $masterManager = Double::for(CacheManager::class);
        $masterManager->expects('allowSerializableClassesUsing');
        $masterManager->expects('store')->never();
        $workerRepository = Double::for(CacheRepository::class);
        $workerManager = Double::for(CacheManager::class);
        $workerManager->expects('store')->with('worker')->returns($workerRepository);
        $validator = Double::for(ModelCacheStoreValidator::class);
        $validator->expects('validate')
            ->with(Argument::same($workerRepository), 'Auth user provider [users]');
        $listener = null;
        $events = Double::for(Dispatcher::class);
        $events->expects('listen')
            ->with(AfterWorkerStart::class, Argument::capture($listener));
        $application = Double::for(Application::class, override: true);
        $application->expects('make')
            ->with(CacheManager::class)
            ->returns($workerManager);
        $application->expects('make')
            ->with(ConfigRepositoryContract::class)
            ->returns($workerConfig);
        $application->expects('make')->with('events')->returns($events);
        $application->expects('make')
            ->with(ModelCacheStoreValidator::class)
            ->returns($validator);
        $application->expects('runningInConsole')->returns(false);
        $application->expects('booted')->never();

        (new AuthServiceProvider($application->instance()))->boot(
            $masterManager,
            $masterConfig,
            Double::for(GateContract::class, override: true)->instance(),
        );

        $this->assertInstanceOf(Closure::class, $listener);
        $server = Double::for(SwooleServer::class);
        // Store validation runs in request workers and taskworkers, unlike Swoole timer registration.
        $server->taskworker = true;
        $listener(new AfterWorkerStart($server, 8));
    }

    public function testBootReplacesQueryBuilderMacrosWithFreshGate(): void
    {
        $config = new ConfigRepository([
            'auth' => ['providers' => []],
        ]);
        $firstManager = Double::for(CacheManager::class);
        $firstManager->expects('allowSerializableClassesUsing');
        $secondManager = Double::for(CacheManager::class);
        $secondManager->expects('allowSerializableClassesUsing');
        $firstGate = Double::for(GateContract::class, override: true);
        $firstGate->expects('scope')->never();
        $secondGate = Double::for(GateContract::class, override: true);
        $secondGate->expects('scope')
            ->with('edit', Argument::type(EloquentBuilder::class))
            ->resolves(static fn (mixed $ability, EloquentBuilder $query): EloquentBuilder => $query);
        $firstApplication = $this->consoleApplication();
        $firstApplication->expects('booted');
        $secondApplication = $this->consoleApplication();
        $secondApplication->expects('booted');

        (new AuthServiceProvider($firstApplication->instance()))->boot($firstManager, $config, $firstGate->instance());
        (new AuthServiceProvider($secondApplication->instance()))->boot($secondManager, $config, $secondGate->instance());

        $query = new EloquentBuilder(Double::for(QueryBuilder::class));

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
        $manager = Double::for(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Authentication provider [users] model must be an Eloquent authenticatable class.');

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
        $manager = Double::for(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Authentication provider [users] cache store must be a string or null.');

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
        $manager = Double::for(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Authentication provider [users] cache TTL must be a positive integer.');

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
        $manager = Double::for(CacheManager::class);
        $startup = $this->bootAndCaptureStartupValidation($manager, $config);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Authentication provider [users] cache tags must be an array of strings or null.');

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
        $repository = Double::for(CacheRepository::class);
        $manager = Double::for(CacheManager::class);
        $manager->expects('store')->with('redis')->returns($repository);
        $validator = Double::for(ModelCacheStoreValidator::class);
        $validator->expects('validate')
            ->with(Argument::same($repository), 'Auth user provider [users]');
        $validator->expects('validateAnyModeTags')
            ->with(Argument::same($repository), 'Auth user provider [users]')
            ->throws(new InvalidArgumentException('TagMode::Any is required.'));
        $startup = $this->bootAndCaptureStartupValidation($manager, $config, $validator);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('TagMode::Any is required.');

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
    private function consoleApplication(): OverriddenDouble
    {
        $application = Double::for(Application::class, override: true);
        $application->expects('runningInConsole')->returns(true);

        return $application;
    }

    /**
     * Boot the provider and capture its console startup validation callback.
     */
    private function bootAndCaptureStartupValidation(
        CacheManager&DoubleInterface $manager,
        ConfigRepositoryContract $config,
        (ModelCacheStoreValidator&DoubleInterface)|null $validator = null,
    ): Closure {
        $manager->expects('allowSerializableClassesUsing');
        $startup = null;
        $application = $this->consoleApplication();

        if ($validator !== null) {
            $application->expects('make')
                ->with(ModelCacheStoreValidator::class)
                ->returns($validator);
        }

        $application->expects('booted')
            ->with(Argument::capture($startup));

        (new AuthServiceProvider($application->instance()))->boot(
            $manager,
            $config,
            Double::for(GateContract::class, override: true)->instance(),
        );

        $this->assertInstanceOf(Closure::class, $startup);

        return $startup;
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
