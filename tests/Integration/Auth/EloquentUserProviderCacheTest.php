<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\EloquentUserProviderCacheTest;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionClass;

#[WithMigration]
class EloquentUserProviderCacheTest extends TestCase
{
    use RefreshDatabase;

    protected const string DEFAULT_KEY_PREFIX = 'auth_users';

    protected MockInterface $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Swap the cache manager with a Mockery double so we can verify
        // get()/put()/forget() calls without a real backend. The mock store
        // returned by the manager is a RedisStore instance, which passes
        // the supported-stores whitelist.
        $this->cacheManager = m::mock(CacheManager::class);
        $this->app->instance('cache', $this->cacheManager);
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

    public function testCacheIsClearedOnUserSave()
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $this->makeCachedProvider();

        $user->name = 'Updated';
        $user->save();
    }

    public function testCacheIsClearedOnUserDelete()
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $this->makeCachedProvider();

        $user->delete();
    }

    public function testDescriptorsDedupeOnIdenticalConfig()
    {
        $this->stubCache();

        $this->makeCachedProvider();
        $this->makeCachedProvider();

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $descriptors = $reflection->getStaticPropertyValue('cachedProviders');

        $this->assertArrayHasKey(User::class, $descriptors);
        $this->assertCount(1, $descriptors[User::class]);
    }

    public function testModelEventInvalidatesAllDescriptorsForSameModel()
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

        $providerA = new EloquentUserProvider($this->app['hash'], User::class);
        $providerA->enableCache('redis-a');

        $providerB = new EloquentUserProvider($this->app['hash'], User::class);
        $providerB->enableCache('redis-b', 300, 'admin_users');

        $user->name = 'Updated';
        $user->save();
    }

    public function testModelEventListenersRegisteredOnlyOnce()
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

        $providerA = new EloquentUserProvider($this->app['hash'], User::class);
        $providerA->enableCache('redis-a');

        $providerB = new EloquentUserProvider($this->app['hash'], User::class);
        $providerB->enableCache('redis-b', 300, 'admin_users');

        $user->name = 'Updated';
        $user->save();
    }

    // ------------------------------------------------------------------
    // Cache invalidation — provider writes
    // ------------------------------------------------------------------

    public function testUpdateRememberTokenClearsCache()
    {
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $provider = $this->makeCachedProvider();

        $provider->updateRememberToken($user, 'new-remember-token');
    }

    public function testRehashPasswordClearsCache()
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

    public function testEnableCacheSkipsListenerRegistrationWhenDispatcherAbsent()
    {
        $this->stubCache();

        // Drop the dispatcher, then enable caching. The provider should
        // populate its descriptor but skip listener registration, leaving
        // $cacheEventsRegistered untouched for this model.
        Model::unsetEventDispatcher();

        $provider = new EloquentUserProvider($this->app['hash'], User::class);
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

    public function testRetrieveByIdCachesResultWithEagerLoadedRelations()
    {
        // A withQuery callback that runs during the DB fetch should affect
        // the first (cache-miss) retrieval. Subsequent calls hit the cache
        // and return the cached User instance without re-running the query.
        $user = User::query()->first();
        $expectedKey = $this->buildKey($user->getAuthIdentifier());

        $repo = $this->stubCache();
        $repo->shouldReceive('get')->twice()->with($expectedKey)
            ->andReturn(null, $user); // first call: miss; second: hit
        $repo->shouldReceive('put')->once()->with($expectedKey, m::type(User::class), 300)
            ->andReturn(true);

        $withQueryInvocations = 0;
        $provider = new EloquentUserProvider($this->app['hash'], User::class);
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeCachedProvider(): EloquentUserProvider
    {
        $provider = new EloquentUserProvider($this->app['hash'], User::class);
        $provider->enableCache(null);

        return $provider;
    }

    protected function stubCache(?string $name = null): MockInterface
    {
        $repo = m::mock(CacheRepository::class);
        $repo->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $this->cacheManager->shouldReceive('store')->with($name)->andReturn($repo);

        return $repo;
    }

    protected function buildKey(mixed $identifier): string
    {
        return self::DEFAULT_KEY_PREFIX . ':' . User::class . ':' . $identifier;
    }
}
