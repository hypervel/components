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
     */
    protected function stubCache(string $storeClass, ?string $name = null): MockInterface
    {
        $store = m::mock($storeClass);
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
