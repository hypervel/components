<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StorageStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Connection;
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

    protected const string DEFAULT_KEY_PREFIX = EloquentUserProvider::DEFAULT_CACHE_PREFIX;

    protected MockInterface $cacheManager;

    protected MockInterface $storeValidator;

    protected MockInterface $cacheCoordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::setInstance(new Container);
        $this->cacheManager = m::mock(CacheManager::class);
        $this->storeValidator = m::mock(ModelCacheStoreValidator::class);
        $this->cacheCoordinator = m::mock(ModelCacheCoordinator::class);
        $this->storeValidator->shouldReceive('validate')->byDefault();
        $this->storeValidator->shouldReceive('validateAnyModeTags')->byDefault();
        $container->instance('cache', $this->cacheManager);
        $container->instance(ModelCacheStoreValidator::class, $this->storeValidator);
        $container->instance(ModelCacheCoordinator::class, $this->cacheCoordinator);
    }

    // ------------------------------------------------------------------
    // Cache disabled (default behaviour)
    // ------------------------------------------------------------------

    public function testRetrieveByIdWithoutCacheDoesNotTouchCache()
    {
        $this->cacheManager->shouldNotReceive('store');

        $user = m::mock(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42, useWritePdo: false);

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

        $this->expectCoordinatorFill($repo, $key, $user, readSource: true, expectedWriter: $repo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdReturnsCachedUser()
    {
        $repo = $this->stubCache(RedisStore::class);
        $user = m::mock(Authenticatable::class);

        $this->expectCoordinatorFill($repo, $this->buildDefaultKey(42), $user);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdCachesNullForMissingUser()
    {
        $repo = $this->stubCache(RedisStore::class);
        $key = $this->buildDefaultKey(999);

        $this->expectCoordinatorFill($repo, $key, null, readSource: true, expectedWriter: $repo);

        $provider = $this->providerExpectingDbFetch(null, 999);
        $provider->enableCache(null);

        $this->assertNull($provider->retrieveById(999));
    }

    public function testRetrieveByIdReturnsNullForCachedNull()
    {
        $repo = $this->stubCache(RedisStore::class);

        $this->expectCoordinatorFill($repo, $this->buildDefaultKey(999), null);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertNull($provider->retrieveById(999));
    }

    public function testRetrieveByCredentialsIsNeverCached()
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->cacheCoordinator->shouldNotReceive('fill');

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
        $this->cacheCoordinator->shouldNotReceive('fill');

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

        $this->expectCoordinatorFill($repo, $expectedKey, m::mock(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);
    }

    public function testEnableCacheNormalizesBlankPrefixToDefault()
    {
        // Two enableCache() calls with blank prefixes (null and '') should both
        // produce keys using the provider default. We set up two distinct
        // repositories returned in sequence from store(null).
        $repo1 = m::mock(CacheRepository::class);
        $repo1->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));
        $repo2 = m::mock(CacheRepository::class);
        $repo2->shouldReceive('getStore')->andReturn(m::mock(RedisStore::class));

        $this->cacheManager->shouldReceive('store')->with(null)
            ->andReturn($repo1, $repo2);

        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':42';
        $this->expectCoordinatorFill($repo1, $expectedKey, m::mock(Authenticatable::class));
        $this->expectCoordinatorFill($repo2, $expectedKey, m::mock(Authenticatable::class));

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
        $this->expectCoordinatorFill($repo, $expectedKey, m::mock(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);
    }

    public function testCustomCacheKeyResolverReceivesLookupContext()
    {
        $received = [];
        EloquentUserProvider::resolveUserCacheKeyUsing(function (
            mixed $identifier,
            string $model,
            ?Model $user,
        ) use (&$received): string {
            $received = [$identifier, $model, $user];

            return (string) $identifier;
        });

        $repo = $this->stubCache(RedisStore::class);
        $this->expectCoordinatorFill($repo, $this->buildDefaultKey(42), m::mock(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->assertSame([42, self::MODEL, null], $received);
    }

    public function testCacheKeyAlwaysIncludesFqcnEvenWithCustomResolver()
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => "wrapper:{$id}");

        $repo = $this->stubCache(RedisStore::class);
        $this->expectCoordinatorFill(
            $repo,
            self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':wrapper:42',
            m::mock(Authenticatable::class),
        );

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->addToAssertionCount(1);
    }

    public function testChangingTheModelUsesTheNewModelCacheKeyspace(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->expectCoordinatorFill(
            $repo,
            self::DEFAULT_KEY_PREFIX . ':' . EloquentCacheProviderAlternateUserStub::class . ':42',
            m::mock(Authenticatable::class),
        );

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);
        $provider->setModel(EloquentCacheProviderAlternateUserStub::class);

        $provider->retrieveById(42);
    }

    public function testChangingTheModelRegistersDeduplicatedDescriptorsForBothKeyspaces(): void
    {
        $this->stubCache(RedisStore::class);
        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->setModel(EloquentCacheProviderAlternateUserStub::class);
        $provider->setModel(EloquentCacheProviderAlternateUserStub::class);
        $provider->setModel(self::MODEL);

        $descriptors = (new ReflectionClass(EloquentUserProvider::class))
            ->getStaticPropertyValue('cachedProviders');

        $this->assertCount(1, $descriptors[self::MODEL]);
        $this->assertCount(1, $descriptors[EloquentCacheProviderAlternateUserStub::class]);
    }

    #[DataProvider('distinctCacheDescriptorProvider')]
    public function testDistinctStoreAndPrefixPairsRegisterSeparateDescriptors(
        string $firstStore,
        string $firstPrefix,
        string $secondStore,
        string $secondPrefix,
    ): void {
        $this->stubCache(RedisStore::class, $firstStore);
        $this->stubCache(RedisStore::class, $secondStore);

        $this->providerWithoutDbFetch()->enableCache($firstStore, prefix: $firstPrefix);
        $this->providerWithoutDbFetch()->enableCache($secondStore, prefix: $secondPrefix);

        $descriptors = (new ReflectionClass(EloquentUserProvider::class))
            ->getStaticPropertyValue('cachedProviders');

        $this->assertCount(2, $descriptors[self::MODEL]);
    }

    public static function distinctCacheDescriptorProvider(): iterable
    {
        yield 'ordinary pairs' => ['redis-a', 'auth_user', 'redis-b', 'admin_users'];
        yield 'delimiter-containing pairs' => ['a', 'b|c', 'a|b', 'c'];
    }

    // ------------------------------------------------------------------
    // Cache TTL validation
    // ------------------------------------------------------------------

    #[DataProvider('invalidCacheTtlProvider')]
    public function testEnableCacheRejectsNonPositiveTtl(int $ttl): void
    {
        $this->cacheManager->shouldNotReceive('store');

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The auth user cache TTL must be greater than zero.');

        $provider->enableCache(null, $ttl);
    }

    public static function invalidCacheTtlProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    // ------------------------------------------------------------------
    // Model cache store validation
    // ------------------------------------------------------------------

    #[DataProvider('supportedStoreProvider')]
    public function testEnableCacheAcceptsSupportedStores(string $storeClass)
    {
        $repo = $this->stubCache($storeClass);
        $this->storeValidator->shouldReceive('validate')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']');

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
    }

    #[DataProvider('unsupportedStoreProvider')]
    public function testEnableCacheRejectsUnsupportedStores(string $storeClass)
    {
        $repo = $this->stubCache($storeClass);
        $this->storeValidator->shouldReceive('validate')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andThrow(new InvalidArgumentException('does not support cache store'));

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
        yield 'Storage' => [StorageStore::class];
        yield 'Stack' => [StackStore::class];
    }

    public function testEnableCacheLeavesProviderInDisabledStateWhenValidationFails()
    {
        $repo = $this->stubCache(ArrayStore::class);
        $this->storeValidator->shouldReceive('validate')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andThrow(new InvalidArgumentException('does not support cache store'));

        $user = m::mock(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42, useWritePdo: false);

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

    public function testModelStoreValidationRunsBeforeAuthTagValidation(): void
    {
        $sequence = [];
        $store = m::mock(RedisStore::class);
        $repo = m::mock(CacheRepository::class);
        $repo->shouldReceive('getStore')->andReturn($store);
        $this->cacheManager->shouldReceive('store')->once()->with(null)->andReturn($repo);
        $this->storeValidator->shouldReceive('validate')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andReturnUsing(function () use (&$sequence): void {
                $sequence[] = 'model';
            });
        $this->storeValidator->shouldReceive('validateAnyModeTags')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andReturnUsing(function () use (&$sequence): void {
                $sequence[] = 'tags';
            });

        $this->providerWithoutDbFetch()->enableCache(null, tags: ['auth_users']);

        $this->assertSame(['model', 'tags'], $sequence);
    }

    // ------------------------------------------------------------------
    // Manual invalidation
    // ------------------------------------------------------------------

    public function testClearUserCacheRemovesCachedEntry()
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->cacheCoordinator->shouldReceive('invalidate')
            ->once()
            ->with($repo, $this->buildDefaultKey(42));

        $provider = $this->providerForCacheClear();
        $provider->enableCache(null);

        $provider->clearUserCache(42);
    }

    public function testClearUserCacheUsesCustomKeyResolver()
    {
        $received = [];
        EloquentUserProvider::resolveUserCacheKeyUsing(function (
            mixed $identifier,
            string $model,
            ?Model $user,
        ) use (&$received): string {
            $received = [$identifier, $model, $user];

            return "tenant:{$identifier}";
        });

        $repo = $this->stubCache(RedisStore::class);
        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':tenant:42';
        $this->cacheCoordinator->shouldReceive('invalidate')->once()->with($repo, $expectedKey);

        $provider = $this->providerForCacheClear();
        $provider->enableCache(null);

        $provider->clearUserCache(42);

        $this->assertSame([42, self::MODEL, null], $received);
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

    #[DataProvider('invalidCacheTagsProvider')]
    public function testEnableCacheRejectsNonStringTagsBeforeResolvingStore(array $tags): void
    {
        $this->cacheManager->shouldNotReceive('store');

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The auth user cache tags must contain only strings.');

        $provider->enableCache(null, tags: $tags);
    }

    public static function invalidCacheTagsProvider(): iterable
    {
        yield 'integer' => [[123]];
        yield 'null' => [[null]];
        yield 'mixed' => [['auth_users', true]];
    }

    public function testEnableCacheAcceptsTagsWhenValidationPasses()
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->storeValidator->shouldReceive('validateAnyModeTags')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']');

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertTrue($provider->isCacheEnabled());
    }

    public function testEnableCacheRejectsTagsWithAllModeStore()
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->storeValidator->shouldReceive('validateAnyModeTags')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andThrow(new InvalidArgumentException('TagMode::Any is required.'));

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TagMode::Any/');

        $provider->enableCache(null, tags: ['auth_users']);
    }

    #[DataProvider('nonTaggableWhitelistedStoreProvider')]
    public function testEnableCacheRejectsTagsWithNonTaggableStore(string $storeClass)
    {
        $repo = $this->stubCache($storeClass);
        $this->storeValidator->shouldReceive('validateAnyModeTags')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andThrow(new InvalidArgumentException('The cache store does not support tags.'));

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not support tags/');

        $provider->enableCache(null, tags: ['auth_users']);
    }

    public static function nonTaggableWhitelistedStoreProvider(): iterable
    {
        yield 'File' => [FileStore::class];
        yield 'Database' => [DatabaseStore::class];
        yield 'Swoole' => [SwooleStore::class];
    }

    public function testRetrieveByIdMissUsesTaggedRepoForPutWhenTagsConfigured()
    {
        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('tags')->once()->with(['auth_users'])->andReturn($taggedRepo);
        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $taggedRepo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdUsesTaggedRepoWhenTagsConfigured()
    {
        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);

        $plainRepo->shouldNotReceive('tags');
        $this->expectCoordinatorFill($plainRepo, $this->buildDefaultKey(42), $user);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testClearUserCacheUsesPlainRepoEvenWhenTagsConfigured()
    {
        $plainRepo = $this->stubCache(RedisStore::class);

        $this->cacheCoordinator->shouldReceive('invalidate')
            ->once()
            ->with($plainRepo, $this->buildDefaultKey(42));
        $plainRepo->shouldNotReceive('tags');

        $provider = $this->providerForCacheClear();
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->clearUserCache(42);
    }

    public function testEffectiveTagsCombineStaticAndDynamic()
    {
        EloquentUserProvider::resolveUserCacheTagsUsing(fn (): array => ['scope:a']);

        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('tags')->once()->with(['auth_users', 'scope:a'])->andReturn($taggedRepo);
        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $taggedRepo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
    }

    public function testEffectiveTagsAreJustStaticWhenNoResolver()
    {
        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = m::mock(CacheRepository::class);
        $user = m::mock(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->shouldReceive('tags')->once()->with(['auth_users'])->andReturn($taggedRepo);
        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $taggedRepo);

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

        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo1 = m::mock(CacheRepository::class);
        $taggedRepo2 = m::mock(CacheRepository::class);
        $user1 = m::mock(Authenticatable::class);
        $user2 = m::mock(Authenticatable::class);
        $key1 = $this->buildDefaultKey(42);
        $key2 = $this->buildDefaultKey(43);

        $plainRepo->shouldReceive('tags')->once()->with(['auth_users', 'scope:1'])->andReturn($taggedRepo1);
        $plainRepo->shouldReceive('tags')->once()->with(['auth_users', 'scope:2'])->andReturn($taggedRepo2);
        $this->expectCoordinatorFill($plainRepo, $key1, $user1, readSource: true, expectedWriter: $taggedRepo1);
        $this->expectCoordinatorFill($plainRepo, $key2, $user2, readSource: true, expectedWriter: $taggedRepo2);

        // Set up two DB fetches with distinct models/IDs.
        $model1 = m::mock(Model::class);
        $builder1 = m::mock(Builder::class);
        $model1->shouldReceive('newQuery')->once()->andReturn($builder1);
        $model1->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder1->shouldReceive('useWritePdo')->once()->andReturnSelf();
        $builder1->shouldReceive('where')->once()->with('id', 42)->andReturn($builder1);
        $builder1->shouldReceive('first')->once()->andReturn($user1);

        $model2 = m::mock(Model::class);
        $builder2 = m::mock(Builder::class);
        $model2->shouldReceive('newQuery')->once()->andReturn($builder2);
        $model2->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        $builder2->shouldReceive('useWritePdo')->once()->andReturnSelf();
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

        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $plainRepo);
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
        $repo = $this->stubCache(RedisStore::class);
        $this->storeValidator->shouldReceive('validateAnyModeTags')
            ->once()
            ->with($repo, 'Auth user cache for model [' . self::MODEL . ']')
            ->andThrow(new InvalidArgumentException('TagMode::Any is required.'));

        $user = m::mock(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42, useWritePdo: false);

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
    protected function providerExpectingDbFetch(
        ?Authenticatable $user,
        mixed $id,
        bool $useWritePdo = true,
    ): EloquentUserProvider&MockObject {
        $model = m::mock(Model::class);
        $builder = m::mock(Builder::class);
        $model->shouldReceive('newQuery')->once()->andReturn($builder);
        $model->shouldReceive('getAuthIdentifierName')->once()->andReturn('id');
        if ($useWritePdo) {
            $builder->shouldReceive('useWritePdo')->once()->andReturnSelf();
        } else {
            $builder->shouldNotReceive('useWritePdo');
        }
        $builder->shouldReceive('where')->once()->with('id', $id)->andReturn($builder);
        $builder->shouldReceive('first')->once()->andReturn($user);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);

        return $provider;
    }

    /**
     * Create a provider with a committed model connection for manual invalidation.
     */
    protected function providerForCacheClear(): EloquentUserProvider&MockObject
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('afterCommitOrNow')
            ->once()
            ->with(m::type(Closure::class))
            ->andReturnUsing(static fn (Closure $callback): mixed => $callback());

        $model = m::mock(Model::class);
        $model->shouldReceive('getConnection')->once()->andReturn($connection);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);

        return $provider;
    }

    /**
     * Expect a provider lookup through the shared cache coordinator.
     */
    protected function expectCoordinatorFill(
        CacheRepository $repository,
        string $key,
        mixed $result,
        bool $readSource = false,
        ?CacheRepository $expectedWriter = null,
    ): void {
        $this->cacheCoordinator->shouldReceive('fill')
            ->once()
            ->with(
                $repository,
                $key,
                300,
                m::type(Closure::class),
                true,
                m::type(Closure::class),
            )
            ->andReturnUsing(function (
                CacheRepository $cache,
                string $receivedKey,
                int $ttl,
                Closure $read,
                bool $cacheNull,
                Closure $writeCache,
            ) use ($result, $readSource, $expectedWriter): mixed {
                if ($expectedWriter !== null) {
                    $this->assertSame($expectedWriter, $writeCache());
                }

                return $readSource ? $read() : $result;
            });
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

class EloquentCacheProviderAlternateUserStub extends Model
{
}
