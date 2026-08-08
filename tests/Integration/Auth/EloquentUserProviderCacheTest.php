<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\EloquentUserProviderCacheTest;

use __PHP_Incomplete_Class;
use Closure;
use Error;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\FileStore;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;

#[WithMigration]
class EloquentUserProviderCacheTest extends TestCase
{
    use RefreshDatabase;

    protected const string DEFAULT_KEY_PREFIX = 'auth_users';

    protected CacheManager $realCacheManager;

    protected MockInterface $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realCacheManager = $this->app->make(CacheManager::class);
        $this->cacheManager = m::mock(CacheManager::class);
        $this->app->instance('cache', $this->cacheManager);
    }

    protected function tearDown(): void
    {
        try {
            $this->realCacheManager->store('auth-file')->flush();
        } finally {
            parent::tearDown();
        }
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            AuthCacheTestServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $configuredClasses = $config->get('cache.testing_serializable_classes');

        $config->set([
            'cache.default' => 'auth-file',
            'cache.serializable_classes' => is_array($configuredClasses)
                ? $configuredClasses
                : false,
            'cache.stores.auth-file' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/auth'),
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => User::class,
                'cache' => [
                    'enabled' => true,
                    'store' => 'auth-file',
                ],
            ],
            'auth.providers.relationship_users' => [
                'driver' => 'eloquent',
                'model' => AuthCacheUser::class,
                'cache' => [
                    'enabled' => true,
                    'store' => 'auth-file',
                ],
            ],
            'database.connections.auth_secondary' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ]);
    }

    protected function allowConfiguredSerializableClass(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'cache.testing_serializable_classes',
            [AuthConfiguredSerializableClass::class],
        );
    }

    protected function allowApplicationRelationClasses(ApplicationContract $app): void
    {
        $app->make('config')->set('cache.testing_allow_relation_classes', true);
    }

    protected function afterRefreshingDatabase(): void
    {
        User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    // ------------------------------------------------------------------
    // Cache invalidation — model events
    // ------------------------------------------------------------------

    public function testCacheIsClearedOnUserSave(): void
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $this->makeCachedProvider();

        $user->name = 'Updated';
        $user->save();
    }

    public function testCacheIsClearedOnUserDelete(): void
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $this->makeCachedProvider();

        $user->delete();
    }

    public function testCacheIsClearedOnlyAfterUserSaveCommits(): void
    {
        $user = User::query()->firstOrFail();
        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($this->buildKey($user->getAuthIdentifier()))->andReturnTrue();

        $this->makeCachedProvider();

        DB::transaction(function () use ($repo, $user): void {
            $user->name = 'Updated';
            $user->save();

            $repo->shouldNotHaveReceived('forget');
        });
    }

    public function testCacheIsClearedOnlyAfterUserDeleteCommits(): void
    {
        $user = User::query()->firstOrFail();
        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($this->buildKey($user->getAuthIdentifier()))->andReturnTrue();

        $this->makeCachedProvider();

        DB::transaction(function () use ($repo, $user): void {
            $user->delete();

            $repo->shouldNotHaveReceived('forget');
        });
    }

    public function testCacheInvalidationIsDiscardedWhenUserSaveRollsBack(): void
    {
        $user = User::query()->firstOrFail();
        $repo = $this->stubCache();
        $repo->shouldNotReceive('forget');

        $this->makeCachedProvider();

        try {
            DB::transaction(function () use ($user): void {
                $user->name = 'Updated';
                $user->save();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }
    }

    public function testCacheInvalidationFollowsTheUserConnectionTransaction(): void
    {
        $user = User::query()->firstOrFail();
        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($this->buildKey($user->getAuthIdentifier()))->andReturnTrue();
        $default = DB::connection();
        $secondary = DB::connection('auth_secondary');

        $this->makeCachedProvider();

        $default->beginTransaction();
        $secondary->beginTransaction();

        try {
            $this->fireUserEvent('saved', $user);

            $secondary->commit();
            $repo->shouldNotHaveReceived('forget');

            $default->commit();
        } finally {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }

            if ($default->transactionLevel() > 1) {
                $default->rollBack(1);
            }
        }
    }

    public function testCacheInvalidationRunsImmediatelyWithoutManagerOrTransaction(): void
    {
        $user = (new User)->setConnection('auth_secondary');
        $user->setRawAttributes(['id' => 1], true);
        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($this->buildKey(1))->andReturnTrue();
        $connection = DB::connection('auth_secondary');
        $manager = $connection->getTransactionManager();

        $this->makeCachedProvider();
        $connection->unsetTransactionManager();

        try {
            $this->fireUserEvent('saved', $user);
        } finally {
            $connection->setTransactionManager($manager);
        }
    }

    public function testCacheInvalidationFailsClosedWithoutManagerDuringTransaction(): void
    {
        $user = (new User)->setConnection('auth_secondary');
        $user->setRawAttributes(['id' => 1], true);
        $this->stubCache()->shouldNotReceive('forget');
        $connection = DB::connection('auth_secondary');
        $manager = $connection->getTransactionManager();

        $this->makeCachedProvider();
        $connection->unsetTransactionManager();
        $connection->beginTransaction();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Transactions Manager has not been set.');

            $this->fireUserEvent('saved', $user);
        } finally {
            $connection->rollBack();
            $connection->setTransactionManager($manager);
        }
    }

    public function testCacheInvalidationCapturesTheEventKeyAndReadsDescriptorsAtCommit(): void
    {
        $user = User::query()->firstOrFail();
        $scope = 'before';
        $resolverCalls = 0;

        EloquentUserProvider::resolveUserCacheKeyUsing(function (mixed $identifier) use (&$resolverCalls, &$scope): string {
            ++$resolverCalls;

            return "{$scope}:{$identifier}";
        });

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')
            ->once()
            ->with(self::DEFAULT_KEY_PREFIX . ':' . User::class . ':before:' . $user->getAuthIdentifier())
            ->andReturnTrue();
        $repo->shouldReceive('forget')
            ->once()
            ->with('admin_users:' . User::class . ':before:' . $user->getAuthIdentifier())
            ->andReturnTrue();

        $this->makeCachedProvider();

        DB::transaction(function () use (&$scope, $user): void {
            $this->fireUserEvent('saved', $user);
            $scope = 'after';

            $provider = new EloquentUserProvider($this->app->make('hash'), User::class);
            $provider->enableCache(null, prefix: 'admin_users');
        });

        $this->assertSame(1, $resolverCalls);
    }

    public function testModelEventInvalidationProvidesProviderModelAndUser(): void
    {
        $user = User::query()->firstOrFail();
        $received = [];

        EloquentUserProvider::resolveUserCacheKeyUsing(function (
            mixed $identifier,
            string $model,
            ?Model $resolvedUser,
        ) use (&$received): string {
            $received[] = [$identifier, $model, $resolvedUser];

            return 'owner:' . $resolvedUser?->getKey();
        });

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')
            ->twice()
            ->with(self::DEFAULT_KEY_PREFIX . ':' . User::class . ':owner:' . $user->getKey())
            ->andReturn(true);

        $this->makeCachedProvider();

        $user->name = 'Updated';
        $user->save();
        $user->delete();

        $this->assertCount(2, $received);

        foreach ($received as [$identifier, $model, $resolvedUser]) {
            $this->assertSame($user->getAuthIdentifier(), $identifier);
            $this->assertSame(User::class, $model);
            $this->assertSame($user, $resolvedUser);
        }
    }

    public function testDescriptorsDedupeOnIdenticalConfig(): void
    {
        $this->stubCache();

        $this->makeCachedProvider();
        $this->makeCachedProvider();

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $descriptors = $reflection->getStaticPropertyValue('cachedProviders');

        $this->assertArrayHasKey(User::class, $descriptors);
        $this->assertCount(1, $descriptors[User::class]);
    }

    public function testModelEventInvalidatesAllDescriptorsForSameModel(): void
    {
        // Two distinct provider configurations for the same model should
        // produce two descriptors; saving the user should clear both keys.
        $repoA = $this->stubCache('redis-a');
        $repoB = $this->stubCache('redis-b');

        $user = User::query()->first();
        $keyA = self::DEFAULT_KEY_PREFIX . ':' . User::class . ':' . $user->getAuthIdentifier();
        $keyB = 'admin_users:' . User::class . ':' . $user->getAuthIdentifier();

        $repoA->shouldReceive('forget')->once()->with($keyA)->andReturn(true);
        $repoB->shouldReceive('forget')->once()->with($keyB)->andReturn(true);

        $providerA = new EloquentUserProvider($this->app->make('hash'), User::class);
        $providerA->enableCache('redis-a');

        $providerB = new EloquentUserProvider($this->app->make('hash'), User::class);
        $providerB->enableCache('redis-b', 300, 'admin_users');

        $user->name = 'Updated';
        $user->save();
    }

    public function testChangingProviderModelKeepsBothModelKeyspacesInvalidatable(): void
    {
        $user = User::query()->firstOrFail();
        $alternateUser = AuthCacheAlternateUser::query()->firstOrFail();
        $repo = $this->stubCache();
        $repo->shouldReceive('forget')
            ->once()
            ->with($this->buildKey($user->getAuthIdentifier()))
            ->andReturnTrue();
        $repo->shouldReceive('forget')
            ->once()
            ->with(self::DEFAULT_KEY_PREFIX . ':' . AuthCacheAlternateUser::class . ':' . $alternateUser->getAuthIdentifier())
            ->andReturnTrue();

        $provider = new EloquentUserProvider($this->app->make('hash'), User::class);
        $provider->enableCache(null);
        $provider->setModel(AuthCacheAlternateUser::class);

        $user->name = 'Updated through the original model';
        $user->save();

        $alternateUser->name = 'Updated through the replacement model';
        $alternateUser->save();
    }

    public function testModelEventListenersRegisteredOnlyOnce(): void
    {
        // Two distinct providers with different configs. If the save/deleted
        // listeners were attached per-enableCache, the single save below would
        // invoke forget 4 times (2 listeners × 2 descriptors). We expect
        // exactly 2 forget calls — one listener, iterating 2 descriptors.
        $repoA = $this->stubCache('redis-a');
        $repoB = $this->stubCache('redis-b');

        $user = User::query()->first();
        $keyA = self::DEFAULT_KEY_PREFIX . ':' . User::class . ':' . $user->getAuthIdentifier();
        $keyB = 'admin_users:' . User::class . ':' . $user->getAuthIdentifier();

        $repoA->shouldReceive('forget')->once()->with($keyA)->andReturn(true);
        $repoB->shouldReceive('forget')->once()->with($keyB)->andReturn(true);

        $providerA = new EloquentUserProvider($this->app->make('hash'), User::class);
        $providerA->enableCache('redis-a');

        $providerB = new EloquentUserProvider($this->app->make('hash'), User::class);
        $providerB->enableCache('redis-b', 300, 'admin_users');

        $user->name = 'Updated';
        $user->save();
    }

    // ------------------------------------------------------------------
    // Cache invalidation — provider writes
    // ------------------------------------------------------------------

    public function testUpdateRememberTokenClearsCache(): void
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $provider = $this->makeCachedProvider();

        $provider->updateRememberToken($user, 'new-remember-token');
    }

    public function testRehashPasswordClearsCache(): void
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $provider = $this->makeCachedProvider();

        $provider->rehashPasswordIfRequired($user, ['password' => 'newpassword'], force: true);
    }

    // ------------------------------------------------------------------
    // Dispatcher ordering
    // ------------------------------------------------------------------

    public function testEnableCacheSkipsListenerRegistrationWhenDispatcherAbsent(): void
    {
        $this->stubCache();

        // Drop the dispatcher, then enable caching. The provider should
        // populate its descriptor but skip listener registration, leaving
        // $cacheEventsRegistered untouched for this model.
        Model::unsetEventDispatcher();

        $provider = new EloquentUserProvider($this->app->make('hash'), User::class);
        $provider->enableCache(null);

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $descriptors = $reflection->getStaticPropertyValue('cachedProviders');
        $registered = $reflection->getStaticPropertyValue('cacheEventsRegistered');

        $this->assertArrayHasKey(User::class, $descriptors);
        $this->assertArrayNotHasKey(User::class, $registered);
    }

    // ------------------------------------------------------------------
    // withQuery() compatibility
    // ------------------------------------------------------------------

    public function testRetrieveByIdCachesResultWithEagerLoadedRelations(): void
    {
        // A withQuery callback that runs during the DB fetch should affect
        // the first (cache-miss) retrieval. Subsequent calls hit the cache
        // and return the cached User instance without re-running the query.
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('rememberNullable')
            ->twice()
            ->with($expectedKey, 300, m::type(Closure::class))
            ->andReturnUsing(
                fn (string $key, int $ttl, Closure $callback) => $callback(),
                fn (string $key, int $ttl, Closure $callback) => $user,
            );

        $withQueryInvocations = 0;
        $provider = new EloquentUserProvider($this->app->make('hash'), User::class);
        $provider->enableCache(null);
        $provider->withQuery(function ($builder) use (&$withQueryInvocations): void {
            ++$withQueryInvocations;
        });

        $first = $provider->retrieveById($user->getAuthIdentifier());
        $second = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $withQueryInvocations, 'withQuery callback should run only on the cache-miss fetch');
    }

    public function testAutomaticProviderClassCachesRootUserWithoutASecondQuery(): void
    {
        $provider = $this->makeRealCachedProvider();
        $user = User::query()->firstOrFail();

        DB::enableQueryLog();

        $first = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(User::class, $first);
        $this->assertSame(1, $this->countQueriesForTable('users'));

        DB::flushQueryLog();

        $second = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(User::class, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame(0, $this->countQueriesForTable('users'));
    }

    #[DefineEnvironment('allowConfiguredSerializableClass')]
    public function testConfiguredClassesUnionWithAutomaticProviderClasses(): void
    {
        $this->app->instance('cache', $this->realCacheManager);
        $store = $this->realCacheManager->build([
            'driver' => 'array',
            'serialize' => true,
        ]);
        $store->put('objects', [
            new User,
            new AuthConfiguredSerializableClass,
        ], 60);

        $objects = $store->get('objects');

        $this->assertInstanceOf(User::class, $objects[0]);
        $this->assertInstanceOf(AuthConfiguredSerializableClass::class, $objects[1]);
    }

    public function testUndeclaredToOneRelationIsIncompleteAndOmittedFromArrayOutput(): void
    {
        $this->createRelationTables();
        $user = AuthCacheUser::query()->firstOrFail();
        AuthCacheProfile::forceCreate([
            'user_id' => $user->getKey(),
            'name' => 'Profile',
        ]);
        $provider = $this->makeRealCachedProvider(AuthCacheUser::class);
        $provider->withQuery(
            static fn (Builder $query): Builder => $query->with('profile'),
        );

        $first = $provider->retrieveById($user->getAuthIdentifier());
        $second = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(AuthCacheProfile::class, $first?->getRelation('profile'));
        $this->assertInstanceOf(AuthCacheUser::class, $second);
        $this->assertTrue($second->relationLoaded('profile'));
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $second->getRelation('profile'));

        try {
            $second->getRelation('profile')->getKey();
            $this->fail('Expected incomplete relation access to fail.');
        } catch (Error $exception) {
            $this->assertStringContainsString(AuthCacheProfile::class, $exception->getMessage());
        }

        $this->assertArrayNotHasKey('profile', $second->toArray());
    }

    public function testUndeclaredToManyModelsRemainIncompleteInsideAutomaticCollection(): void
    {
        $this->createRelationTables();
        $user = AuthCacheUser::query()->firstOrFail();
        AuthCachePost::forceCreate([
            'user_id' => $user->getKey(),
            'title' => 'First',
        ]);
        AuthCachePost::forceCreate([
            'user_id' => $user->getKey(),
            'title' => 'Second',
        ]);
        $provider = $this->makeRealCachedProvider(AuthCacheUser::class);
        $provider->withQuery(
            static fn (Builder $query): Builder => $query->with('posts'),
        );

        $first = $provider->retrieveById($user->getAuthIdentifier());
        $second = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(EloquentCollection::class, $first?->getRelation('posts'));
        $this->assertInstanceOf(AuthCachePost::class, $first->getRelation('posts')->first());
        $this->assertInstanceOf(AuthCacheUser::class, $second);
        $this->assertInstanceOf(EloquentCollection::class, $second->getRelation('posts'));
        $this->assertContainsOnlyInstancesOf(
            __PHP_Incomplete_Class::class,
            $second->getRelation('posts')->all(),
        );
    }

    #[DefineEnvironment('allowApplicationRelationClasses')]
    public function testDeclaredApplicationRelationsRoundTripCompletely(): void
    {
        $this->createRelationTables();
        $user = AuthCacheUser::query()->firstOrFail();
        AuthCacheProfile::forceCreate([
            'user_id' => $user->getKey(),
            'name' => 'Profile',
        ]);
        AuthCachePost::forceCreate([
            'user_id' => $user->getKey(),
            'title' => 'Post',
        ]);
        $provider = $this->makeRealCachedProvider(AuthCacheUser::class);
        $provider->withQuery(
            static fn (Builder $query): Builder => $query->with(['profile', 'posts']),
        );

        $provider->retrieveById($user->getAuthIdentifier());
        $cached = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertInstanceOf(AuthCacheUser::class, $cached);
        $this->assertInstanceOf(AuthCacheProfile::class, $cached->getRelation('profile'));
        $this->assertInstanceOf(EloquentCollection::class, $cached->getRelation('posts'));
        $this->assertInstanceOf(AuthCachePost::class, $cached->getRelation('posts')->first());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeCachedProvider(): EloquentUserProvider
    {
        $provider = new EloquentUserProvider($this->app->make('hash'), User::class);
        $provider->enableCache(null);

        return $provider;
    }

    protected function fireUserEvent(string $event, User $user): void
    {
        $this->app->make('events')->dispatch("eloquent.{$event}: " . User::class, $user);
    }

    /**
     * Create a provider backed by the real serializing cache manager.
     *
     * @param class-string<\Hypervel\Contracts\Auth\Authenticatable&Model> $model
     */
    protected function makeRealCachedProvider(string $model = User::class): EloquentUserProvider
    {
        $this->app->instance('cache', $this->realCacheManager);

        $provider = new EloquentUserProvider($this->app->make('hash'), $model);
        $provider->enableCache('auth-file');

        return $provider;
    }

    /**
     * Create the relation tables used by serialization tests.
     */
    protected function createRelationTables(): void
    {
        $schema = $this->app->make('db')->connection()->getSchemaBuilder();

        $schema->create('auth_cache_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('name');
        });

        $schema->create('auth_cache_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('title');
        });
    }

    /**
     * Count logged select queries for a table.
     */
    protected function countQueriesForTable(string $table): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with(strtolower($query['query'] ?? ''), 'select')
                && str_contains($query['query'] ?? '', $table)
        ));
    }

    protected function stubCache(?string $name = null): MockInterface
    {
        $repo = m::mock(CacheRepository::class);
        $repo->shouldReceive('getStore')->andReturn(m::mock(FileStore::class));
        $this->cacheManager->shouldReceive('store')->with($name)->andReturn($repo);

        return $repo;
    }

    protected function buildKey(mixed $identifier): string
    {
        return self::DEFAULT_KEY_PREFIX . ':' . User::class . ':' . $identifier;
    }
}

class AuthCacheTestServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the test service provider.
     */
    public function boot(): void
    {
        $config = $this->app->make('config');

        $this->app->make(CacheManager::class)->allowSerializableClassesUsing(
            static fn (): array => $config->get('cache.testing_allow_relation_classes') === true
                ? [AuthCacheProfile::class, AuthCachePost::class]
                : [],
        );
    }
}

class AuthCacheUser extends User
{
    protected ?string $table = 'users';

    /**
     * Get the user's profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(AuthCacheProfile::class, 'user_id');
    }

    /**
     * Get the user's posts.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(AuthCachePost::class, 'user_id');
    }
}

class AuthCacheAlternateUser extends User
{
    protected ?string $table = 'users';
}

class AuthCacheProfile extends Model
{
    protected ?string $table = 'auth_cache_profiles';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class AuthCachePost extends Model
{
    protected ?string $table = 'auth_cache_posts';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class AuthConfiguredSerializableClass
{
}
