<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\TagMode;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

class AuthEloquentUserProviderCacheTest extends TestCase
{
    protected const string MODEL = EloquentCacheProviderUserStub::class;

    protected const string DEFAULT_KEY_PREFIX = 'auth_users';

    protected MockInterface $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::setInstance(new Container);
        $this->cacheManager = m::mock(CacheManager::class);
        $container->instance('cache', $this->cacheManager);
    }

    // ------------------------------------------------------------------
    // Cache disabled (default behaviour)
    // ------------------------------------------------------------------

    public function testRetrieveByIdWithoutCacheDoesNotTouchCache()
    {
        $this->cacheManager->shouldNotReceive('store');

        $user = m::mock(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    // ------------------------------------------------------------------
    // Cache enabled — basic operation
    // ------------------------------------------------------------------

    public function testRetrieveByIdCachesMissedLookup()
    {
        $repo = $this->stubCache(RedisStore::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $repo->shouldReceive('get')->once()->with($key)->andReturn(null);
        $repo->shouldReceive('put')->once()->with($key, $user, 300)->andReturn(true);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdReturnsCachedUser()
    {
        $repo = $this->stubCache(RedisStore::class);
        $user = m::mock(Authenticatable::class);

        $repo->shouldReceive('get')->once()->with($this->buildDefaultKey(42))->andReturn($user);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdCachesNullSentinelForMissingUser()
    {
        $repo = $this->stubCache(RedisStore::class);
        $sentinel = ['__auth_null_sentinel' => true];
        $key = $this->buildDefaultKey(999);

        $repo->shouldReceive('get')->once()->with($key)->andReturn(null);
        $repo->shouldReceive('put')->once()->with($key, $sentinel, 300)->andReturn(true);

        $provider = $this->providerExpectingDbFetch(null, 999);
        $provider->enableCache(null);

        $this->assertNull($provider->retrieveById(999));
    }

    public function testRetrieveByIdReturnsNullForCachedSentinel()
    {
        $repo = $this->stubCache(RedisStore::class);
        $sentinel = ['__auth_null_sentinel' => true];

        $repo->shouldReceive('get')->once()->with($this->buildDefaultKey(999))->andReturn($sentinel);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertNull($provider->retrieveById(999));
    }

    public function testRetrieveByCredentialsIsNeverCached()
    {
        $repo = $this->stubCache(RedisStore::class);
        $repo->shouldNotReceive('get');
        $repo->shouldNotReceive('put');

        $expectedUser = m::mock(Authenticatable::class);
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $builder->shouldReceive('where')->once()->with('username', 'u');
        $builder->shouldReceive('first')->once()->andReturn($expectedUser);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $provider->enableCache(null);

        $this->assertSame($expectedUser, $provider->retrieveByCredentials(['username' => 'u']));
    }

    public function testRetrieveByTokenIsNeverCached()
    {
        $repo = $this->stubCache(RedisStore::class);
        $repo->shouldNotReceive('get');
        $repo->shouldNotReceive('put');

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->once()->andReturn('tok');
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder->shouldReceive('where')->once()->with('id', 1)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($user);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveByToken(1, 'tok'));
    }

    // ------------------------------------------------------------------
    // Cache key resolution
    // ------------------------------------------------------------------

    public function testDefaultCacheKeyIncludesFqcnAndIdentifier()
    {
        $repo = $this->stubCache(RedisStore::class);
        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':42';

        $repo->shouldReceive('get')->once()->with($expectedKey)->andReturn(m::mock(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);
    }

    public function testEnableCacheNormalizesBlankPrefixToDefault()
    {
        // Two enableCache() calls with blank prefixes (null and '') should both
        // produce keys using the 'auth_users' default. We set up two distinct
        // repositories returned in sequence from store(null).
        $repo1 = m::mock(CacheRepository::class);
        $repo1->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $repo2 = m::mock(CacheRepository::class);
        $repo2->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));

        $this->cacheManager->shouldReceive('store')->with(null)
            ->andReturn($repo1, $repo2);

        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':42';
        $repo1->shouldReceive('get')->once()->with($expectedKey)->andReturn(m::mock(Authenticatable::class));
        $repo2->shouldReceive('get')->once()->with($expectedKey)->andReturn(m::mock(Authenticatable::class));

        $providerNull = $this->providerWithoutDbFetch();
        $providerNull->enableCache(null, 300, null);
        $providerNull->retrieveById(42);

        $providerEmpty = $this->providerWithoutDbFetch();
        $providerEmpty->enableCache(null, 300, '');
        $providerEmpty->retrieveById(42);
    }

    public function testCustomCacheKeyResolverIsUsed()
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => "tenant5:{$id}");

        $repo = $this->stubCache(RedisStore::class);
        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':tenant5:42';
        $repo->shouldReceive('get')->once()->with($expectedKey)->andReturn(m::mock(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);
    }

    public function testCustomCacheKeyResolverReceivesIdentifier()
    {
        $received = null;
        EloquentUserProvider::resolveUserCacheKeyUsing(function (mixed $id) use (&$received): string {
            $received = $id;

            return (string) $id;
        });

        $repo = $this->stubCache(RedisStore::class);
        $repo->shouldReceive('get')->once()->andReturn(m::mock(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->assertSame(42, $received);
    }

    public function testCacheKeyAlwaysIncludesFqcnEvenWithCustomResolver()
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => "wrapper:{$id}");

        $capturedKey = null;
        $repo = $this->stubCache(RedisStore::class);
        $repo->shouldReceive('get')->once()->andReturnUsing(function (string $key) use (&$capturedKey) {
            $capturedKey = $key;

            return m::mock(Authenticatable::class);
        });

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->assertStringContainsString(self::MODEL, $capturedKey);
    }

    // ------------------------------------------------------------------
    // Supported-store whitelist
    // ------------------------------------------------------------------

    #[DataProvider('supportedStoreProvider')]
    public function testEnableCacheAcceptsSupportedStores(string $storeClass)
    {
        $this->stubCache($storeClass);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertTrue($provider->isCacheEnabled());
    }

    // ------------------------------------------------------------------
    // Data providers
    // ------------------------------------------------------------------

    public static function supportedStoreProvider(): iterable
    {
        yield 'Redis' => [RedisStore::class];
        yield 'Database' => [DatabaseStore::class];
        yield 'File' => [FileStore::class];
        yield 'Swoole' => [SwooleStore::class];
        yield 'Stack' => [StackStore::class];
    }

    #[DataProvider('unsupportedStoreProvider')]
    public function testEnableCacheRejectsUnsupportedStores(string $storeClass)
    {
        $this->stubCache($storeClass);

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not support cache store/');

        $provider->enableCache(null);
    }

    public static function unsupportedStoreProvider(): iterable
    {
        yield 'Array' => [ArrayStore::class];
        yield 'Null' => [NullStore::class];
        yield 'Session' => [SessionStore::class];
        yield 'Failover' => [FailoverStore::class];
    }

    public function testEnableCacheLeavesProviderInDisabledStateWhenValidationFails()
    {
        $this->stubCache(ArrayStore::class);

        $user = m::mock(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42);

        try {
            $provider->enableCache(null);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertFalse($provider->isCacheEnabled());

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $descriptors = $reflection->getStaticPropertyValue('cachedProviders');
        $registered = $reflection->getStaticPropertyValue('cacheEventsRegistered');
        $this->assertArrayNotHasKey(self::MODEL, $descriptors);
        $this->assertArrayNotHasKey(self::MODEL, $registered);

        // Provider still falls through to the DB path on retrieveById.
        $this->assertSame($user, $provider->retrieveById(42));
    }

    // ------------------------------------------------------------------
    // Manual invalidation
    // ------------------------------------------------------------------

    public function testClearUserCacheRemovesCachedEntry()
    {
        $repo = $this->stubCache(RedisStore::class);
        $repo->shouldReceive('forget')->once()->with($this->buildDefaultKey(42))->andReturn(true);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->clearUserCache(42);
    }

    public function testClearUserCacheUsesCustomKeyResolver()
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => "tenant:{$id}");

        $repo = $this->stubCache(RedisStore::class);
        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':tenant:42';
        $repo->shouldReceive('forget')->once()->with($expectedKey)->andReturn(true);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->clearUserCache(42);
    }

    public function testClearUserCacheIsNoOpWhenCacheDisabled()
    {
        $this->cacheManager->shouldNotReceive('store');

        $provider = $this->providerWithoutDbFetch();

        // No enableCache() — cache is null; clearUserCache must not blow up.
        $provider->clearUserCache(42);

        $this->assertFalse($provider->isCacheEnabled());
    }

    // ------------------------------------------------------------------
    // flushState
    // ------------------------------------------------------------------

    public function testFlushStateClearsAllStaticState()
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => (string) $id);

        $this->stubCache(RedisStore::class);
        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $this->assertNotSame([], $reflection->getStaticPropertyValue('cachedProviders'));

        EloquentUserProvider::flushState();

        $this->assertNull($reflection->getStaticPropertyValue('cacheKeyResolver'));
        $this->assertSame([], $reflection->getStaticPropertyValue('cachedProviders'));
        $this->assertSame([], $reflection->getStaticPropertyValue('cacheEventsRegistered'));
    }

    // ------------------------------------------------------------------
    // Tag support
    // ------------------------------------------------------------------

    public function testEnableCacheAcceptsTagsWithAnyModeRedisStore()
    {
        $this->stubCache(RedisStore::class, tagMode: TagMode::Any);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertTrue($provider->isCacheEnabled());
    }

    public function testEnableCacheRejectsTagsWithAllModeStore()
    {
        $this->stubCache(RedisStore::class, tagMode: TagMode::All);

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TagMode::Any/');

        $provider->enableCache(null, tags: ['auth_users']);
    }

    #[DataProvider('nonTaggableWhitelistedStoreProvider')]
    public function testEnableCacheRejectsTagsWithNonTaggableStore(string $storeClass)
    {
        $this->stubCache($storeClass);

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/require a TaggableStore/');

        $provider->enableCache(null, tags: ['auth_users']);
    }

    public static function nonTaggableWhitelistedStoreProvider(): iterable
    {
        yield 'File' => [FileStore::class];
        yield 'Database' => [DatabaseStore::class];
        yield 'Swoole' => [SwooleStore::class];
        yield 'Stack' => [StackStore::class];
    }

    public function testRetrieveByIdMissUsesTaggedRepoForPutWhenTagsConfigured()
    {
        $plainRepo = $this->stubCache(RedisStore::class, tagMode: TagMode::Any);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('get')->once()->with($key)->andReturn(null);
        $plainRepo->shouldReceive('tags')->once()->with(['auth_users'])->andReturn($taggedRepo);
        $plainRepo->shouldNotReceive('put');
        $taggedRepo->shouldReceive('put')->once()->with($key, $user, 300)->andReturn(true);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdHitUsesPlainRepoEvenWhenTagsConfigured()
    {
        $plainRepo = $this->stubCache(RedisStore::class, tagMode: TagMode::Any);
        $user = m::mock(Authenticatable::class);

        $plainRepo->shouldReceive('get')->once()->with($this->buildDefaultKey(42))->andReturn($user);
        $plainRepo->shouldNotReceive('tags');

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testClearUserCacheUsesPlainRepoEvenWhenTagsConfigured()
    {
        $plainRepo = $this->stubCache(RedisStore::class, tagMode: TagMode::Any);

        $plainRepo->shouldReceive('forget')->once()->with($this->buildDefaultKey(42))->andReturn(true);
        $plainRepo->shouldNotReceive('tags');

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->clearUserCache(42);
    }

    public function testEffectiveTagsCombineStaticAndDynamic()
    {
        EloquentUserProvider::resolveUserCacheTagsUsing(fn (): array => ['scope:a']);

        $plainRepo = $this->stubCache(RedisStore::class, tagMode: TagMode::Any);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('get')->once()->with($key)->andReturn(null);
        $plainRepo->shouldReceive('tags')->once()->with(['auth_users', 'scope:a'])->andReturn($taggedRepo);
        $taggedRepo->shouldReceive('put')->once()->with($key, $user, 300)->andReturn(true);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
    }

    public function testEffectiveTagsAreJustStaticWhenNoResolver()
    {
        $plainRepo = $this->stubCache(RedisStore::class, tagMode: TagMode::Any);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('get')->once()->with($key)->andReturn(null);
        $plainRepo->shouldReceive('tags')->once()->with(['auth_users'])->andReturn($taggedRepo);
        $taggedRepo->shouldReceive('put')->once()->with($key, $user, 300)->andReturn(true);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
    }

    public function testDynamicResolverIsInvokedFreshlyOnEachPut()
    {
        $count = 0;
        EloquentUserProvider::resolveUserCacheTagsUsing(function () use (&$count): array {
            ++$count;

            return ['scope:' . $count];
        });

        $plainRepo = $this->stubCache(RedisStore::class, tagMode: TagMode::Any);
        $taggedRepo1 = m::mock(CacheRepository::class);
        $taggedRepo2 = m::mock(CacheRepository::class);
        $user1 = m::mock(Authenticatable::class);
        $user2 = m::mock(Authenticatable::class);
        $key1 = $this->buildDefaultKey(42);
        $key2 = $this->buildDefaultKey(43);

        $plainRepo->shouldReceive('get')->once()->with($key1)->andReturn(null);
        $plainRepo->shouldReceive('get')->once()->with($key2)->andReturn(null);
        $plainRepo->shouldReceive('tags')->once()->with(['auth_users', 'scope:1'])->andReturn($taggedRepo1);
        $plainRepo->shouldReceive('tags')->once()->with(['auth_users', 'scope:2'])->andReturn($taggedRepo2);
        $taggedRepo1->shouldReceive('put')->once()->with($key1, $user1, 300)->andReturn(true);
        $taggedRepo2->shouldReceive('put')->once()->with($key2, $user2, 300)->andReturn(true);

        // Set up two DB fetches with distinct models/IDs.
        $model1 = m::mock(Model::class);
        $builder1 = m::mock(Builder::class);
        $model1->shouldReceive('newQuery')->once()->andReturn($builder1);
        $model1->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder1->shouldReceive('where')->once()->with('id', 42)->andReturn($builder1);
        $builder1->shouldReceive('first')->once()->andReturn($user1);

        $model2 = m::mock(Model::class);
        $builder2 = m::mock(Builder::class);
        $model2->shouldReceive('newQuery')->once()->andReturn($builder2);
        $model2->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder2->shouldReceive('where')->once()->with('id', 43)->andReturn($builder2);
        $builder2->shouldReceive('first')->once()->andReturn($user2);

        $provider = $this->providerMock();
        $provider->expects($this->exactly(2))->method('createModel')->willReturnOnConsecutiveCalls($model1, $model2);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
        $provider->retrieveById(43);

        $this->assertSame(2, $count);
    }

    public function testDynamicResolverIgnoredWhenNoStaticTagsConfigured()
    {
        $resolverInvoked = false;
        EloquentUserProvider::resolveUserCacheTagsUsing(function () use (&$resolverInvoked): array {
            $resolverInvoked = true;

            return ['scope:a'];
        });

        $plainRepo = $this->stubCache(RedisStore::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('get')->once()->with($key)->andReturn(null);
        $plainRepo->shouldReceive('put')->once()->with($key, $user, 300)->andReturn(true);
        $plainRepo->shouldNotReceive('tags');

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->assertFalse($resolverInvoked);
    }

    public function testFlushStateClearsTagsResolver()
    {
        EloquentUserProvider::resolveUserCacheTagsUsing(fn (): array => ['scope:a']);

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $this->assertNotNull($reflection->getStaticPropertyValue('cacheTagsResolver'));

        EloquentUserProvider::flushState();

        $this->assertNull($reflection->getStaticPropertyValue('cacheTagsResolver'));
    }

    public function testEnableCacheLeavesProviderInDisabledStateWhenTagValidationFails()
    {
        $this->stubCache(RedisStore::class, tagMode: TagMode::All);

        $user = m::mock(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42);

        try {
            $provider->enableCache(null, tags: ['auth_users']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertFalse($provider->isCacheEnabled());

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $descriptors = $reflection->getStaticPropertyValue('cachedProviders');
        $registered = $reflection->getStaticPropertyValue('cacheEventsRegistered');
        $this->assertArrayNotHasKey(self::MODEL, $descriptors);
        $this->assertArrayNotHasKey(self::MODEL, $registered);

        // Provider still falls through to the DB path on retrieveById.
        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRecallingEnableCacheWithoutTagsClearsPreviousTagState()
    {
        // First call uses a Redis store in any-mode (tag-valid), second
        // call uses a plain Redis store (no tags). Set up both upfront so
        // the cache manager returns them in sequence from store(null).
        $store1 = m::mock(RedisStore::class);
        $store1->shouldReceive('getTagMode')->andReturn(TagMode::Any);
        $repo1 = m::mock(CacheRepository::class);
        $repo1->shouldReceive('getStore')->andReturn($store1);

        $store2 = m::mock(RedisStore::class);
        $repo2 = m::mock(CacheRepository::class);
        $repo2->shouldReceive('getStore')->andReturn($store2);

        $this->cacheManager->shouldReceive('store')->with(null)->andReturn($repo1, $repo2);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $cacheTagsProp = $reflection->getProperty('cacheTags');
        $this->assertSame(['auth_users'], $cacheTagsProp->getValue($provider));

        // Re-enable without tags — previous tag state must not survive.
        $provider->enableCache(null);

        $this->assertNull($cacheTagsProp->getValue($provider));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function providerMock(): EloquentUserProvider&MockObject
    {
        $hasher = m::mock(Hasher::class);

        return $this->getMockBuilder(EloquentUserProvider::class)
            ->onlyMethods(['createModel'])
            ->setConstructorArgs([$hasher, self::MODEL])
            ->getMock();
    }

    /**
     * Provider whose createModel() returns a mock Model + Builder chain that
     * yields $user for retrieveById($id).
     */
    protected function providerExpectingDbFetch(?Authenticatable $user, mixed $id): EloquentUserProvider&MockObject
    {
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder->shouldReceive('where')->once()->with('id', $id)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($user);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);

        return $provider;
    }

    /**
     * Provider configured so that createModel() must never be called
     * (cache-hit / cache-disabled paths).
     */
    protected function providerWithoutDbFetch(): EloquentUserProvider&MockObject
    {
        $provider = $this->providerMock();
        $provider->expects($this->never())->method('createModel');

        return $provider;
    }

    /**
     * Stub the cache manager to return a mocked repository backed by an
     * instance of $storeClass. Returns the repository mock so tests can
     * set further expectations on it.
     *
     * If $tagMode is provided, the store mock also responds to
     * getTagMode() with the given mode — used by the tag-support tests
     * that exercise ensureTaggableAnyModeStore().
     */
    protected function stubCache(string $storeClass, ?string $name = null, ?TagMode $tagMode = null): MockInterface
    {
        $store = m::mock($storeClass);

        if ($tagMode !== null) {
            $store->shouldReceive('getTagMode')->andReturn($tagMode);
        }

        $repo = m::mock(CacheRepository::class);
        $repo->shouldReceive('getStore')->andReturn($store);
        $this->cacheManager->shouldReceive('store')->with($name)->andReturn($repo);

        return $repo;
    }

    protected function buildDefaultKey(mixed $identifier): string
    {
        return self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':' . $identifier;
    }
}

class EloquentCacheProviderUserStub extends Model
{
}
