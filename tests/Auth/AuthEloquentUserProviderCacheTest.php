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
use Hypervel\Cache\Repository;
use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StorageStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Foundation\Auth\User;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

class AuthEloquentUserProviderCacheTest extends TestCase
{
    use VerifiesDoubles;

    protected const string MODEL = EloquentCacheProviderUserStub::class;

    protected const string DEFAULT_KEY_PREFIX = EloquentUserProvider::DEFAULT_CACHE_PREFIX;

    protected CacheManager&DoubleInterface $cacheManager;

    protected ModelCacheStoreValidator&DoubleInterface $storeValidator;

    protected ModelCacheCoordinator&DoubleInterface $cacheCoordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::setInstance(new Container);
        $this->cacheManager = Double::for(CacheManager::class);
        $this->storeValidator = Double::for(ModelCacheStoreValidator::class);
        $this->cacheCoordinator = Double::for(ModelCacheCoordinator::class);
        $container->instance('cache', $this->cacheManager);
        $container->instance(ModelCacheStoreValidator::class, $this->storeValidator);
        $container->instance(ModelCacheCoordinator::class, $this->cacheCoordinator);
    }

    // ------------------------------------------------------------------
    // Cache disabled (default behavior)
    // ------------------------------------------------------------------

    public function testRetrieveByIdWithoutCacheDoesNotTouchCache(): void
    {
        $this->cacheManager->expects('store')->never();

        $user = Double::for(Authenticatable::class);
        $provider = $this->providerExpectingDbFetch($user, 42, useWritePdo: false);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    // ------------------------------------------------------------------
    // Cache enabled — basic operation
    // ------------------------------------------------------------------

    public function testRetrieveByIdCachesMissedLookup(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $user = Double::for(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $this->expectCoordinatorFill($repo, $key, $user, readSource: true, expectedWriter: $repo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdReturnsCachedUser(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $user = Double::for(Authenticatable::class);

        $this->expectCoordinatorFill($repo, $this->buildDefaultKey(42), $user);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdCachesNullForMissingUser(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $key = $this->buildDefaultKey(999);

        $this->expectCoordinatorFill($repo, $key, null, readSource: true, expectedWriter: $repo);

        $provider = $this->providerExpectingDbFetch(null, 999);
        $provider->enableCache(null);

        $this->assertNull($provider->retrieveById(999));
    }

    public function testRetrieveByIdReturnsNullForCachedNull(): void
    {
        $repo = $this->stubCache(RedisStore::class);

        $this->expectCoordinatorFill($repo, $this->buildDefaultKey(999), null);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $this->assertNull($provider->retrieveById(999));
    }

    public function testRetrieveByCredentialsIsNeverCached(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->cacheCoordinator->expects('fill')->never();

        $expectedUser = Double::for(Authenticatable::class);
        $model = Double::for(User::class);
        $builder = Double::for(Builder::class);
        $model->expects('newQuery')->returns($builder);
        $builder->expects('where')->with('username', 'u');
        $builder->expects('first')->returns($expectedUser);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $provider->enableCache(null);

        $this->assertSame($expectedUser, $provider->retrieveByCredentials(['username' => 'u']));
    }

    public function testRetrieveByTokenIsNeverCached(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->cacheCoordinator->expects('fill')->never();

        $user = Double::for(Authenticatable::class);
        $user->expects('getRememberToken')->returns('tok');
        $model = Double::for(User::class);
        $builder = Double::for(Builder::class);
        $model->expects('newQuery')->returns($builder);
        $model->expects('getAuthIdentifierName')->returns('id');
        $builder->expects('where')->with('id', 1)->returns($builder);
        $builder->expects('first')->returns($user);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);
        $provider->enableCache(null);

        $this->assertSame($user, $provider->retrieveByToken(1, 'tok'));
    }

    // ------------------------------------------------------------------
    // Cache key resolution
    // ------------------------------------------------------------------

    public function testDefaultCacheKeyIncludesFqcnAndIdentifier(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':42';

        $this->expectCoordinatorFill($repo, $expectedKey, Double::for(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);
    }

    public function testEnableCacheNormalizesBlankPrefixToDefault(): void
    {
        // Two enableCache() calls with blank prefixes (null and '') should both
        // produce keys using the provider default. We set up two distinct
        // repositories returned in sequence from store(null).
        $repo1 = Double::for(CacheRepository::class);
        $repo1->allows('getStore')->returns(Double::for(RedisStore::class));
        $repo2 = Double::for(CacheRepository::class);
        $repo2->allows('getStore')->returns(Double::for(RedisStore::class));

        $this->cacheManager->allows('store')->with(null)
            ->returns($repo1, $repo2);

        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':42';
        $this->expectCoordinatorFill($repo1, $expectedKey, Double::for(Authenticatable::class));
        $this->expectCoordinatorFill($repo2, $expectedKey, Double::for(Authenticatable::class));

        $providerNull = $this->providerWithoutDbFetch();
        $providerNull->enableCache(null, 300, null);
        $providerNull->retrieveById(42);

        $providerEmpty = $this->providerWithoutDbFetch();
        $providerEmpty->enableCache(null, 300, '');
        $providerEmpty->retrieveById(42);
    }

    public function testCustomCacheKeyResolverIsUsed(): void
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => "tenant5:{$id}");

        $repo = $this->stubCache(RedisStore::class);
        $expectedKey = self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':tenant5:42';
        $this->expectCoordinatorFill($repo, $expectedKey, Double::for(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);
    }

    public function testCustomCacheKeyResolverReceivesLookupContext(): void
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
        $this->expectCoordinatorFill($repo, $this->buildDefaultKey(42), Double::for(Authenticatable::class));

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->assertSame([42, self::MODEL, null], $received);
    }

    public function testCacheKeyAlwaysIncludesFqcnEvenWithCustomResolver(): void
    {
        EloquentUserProvider::resolveUserCacheKeyUsing(fn (mixed $id): string => "wrapper:{$id}");

        $repo = $this->stubCache(RedisStore::class);
        $this->expectCoordinatorFill(
            $repo,
            self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':wrapper:42',
            Double::for(Authenticatable::class),
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
            Double::for(Authenticatable::class),
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
        $this->cacheManager->expects('store')->never();

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The auth user cache TTL must be greater than zero.');

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
    public function testEnableCacheAcceptsSupportedStores(string $storeClass): void
    {
        $repo = $this->stubCache($storeClass);
        $this->storeValidator->expects('validate')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']');

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
    public function testEnableCacheRejectsUnsupportedStores(string $storeClass): void
    {
        $repo = $this->stubCache($storeClass);
        $this->storeValidator->expects('validate')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->throws(new InvalidArgumentException('does not support cache store'));

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

    public function testEnableCacheLeavesProviderInDisabledStateWhenValidationFails(): void
    {
        $repo = $this->stubCache(ArrayStore::class);
        $this->storeValidator->expects('validate')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->throws(new InvalidArgumentException('does not support cache store'));

        $user = Double::for(Authenticatable::class);
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
        $store = Double::for(RedisStore::class);
        $repo = Double::for(CacheRepository::class);
        $repo->allows('getStore')->returns($store);
        $this->cacheManager->expects('store')->with(null)->returns($repo);
        $this->storeValidator->expects('validate')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->resolves(function () use (&$sequence): void {
                $sequence[] = 'model';
            });
        $this->storeValidator->expects('validateAnyModeTags')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->resolves(function () use (&$sequence): void {
                $sequence[] = 'tags';
            });

        $this->providerWithoutDbFetch()->enableCache(null, tags: ['auth_users']);

        $this->assertSame(['model', 'tags'], $sequence);
    }

    // ------------------------------------------------------------------
    // Manual invalidation
    // ------------------------------------------------------------------

    public function testClearUserCacheRemovesCachedEntry(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->cacheCoordinator->expects('invalidate')
            ->with(Argument::same($repo), $this->buildDefaultKey(42));

        $provider = $this->providerForCacheClear();
        $provider->enableCache(null);

        $provider->clearUserCache(42);
    }

    public function testClearUserCacheUsesCustomKeyResolver(): void
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
        $this->cacheCoordinator->expects('invalidate')->with(Argument::same($repo), $expectedKey);

        $provider = $this->providerForCacheClear();
        $provider->enableCache(null);

        $provider->clearUserCache(42);

        $this->assertSame([42, self::MODEL, null], $received);
    }

    public function testClearUserCacheIsNoOpWhenCacheDisabled(): void
    {
        $this->cacheManager->expects('store')->never();

        $provider = $this->providerWithoutDbFetch();

        // No enableCache() — cache is null; clearUserCache must not blow up.
        $provider->clearUserCache(42);

        $this->assertFalse($provider->isCacheEnabled());
    }

    // ------------------------------------------------------------------
    // flushState
    // ------------------------------------------------------------------

    public function testFlushStateClearsAllStaticState(): void
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
        $this->cacheManager->expects('store')->never();

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The auth user cache tags must contain only strings.');

        $provider->enableCache(null, tags: $tags);
    }

    public static function invalidCacheTagsProvider(): iterable
    {
        yield 'integer' => [[123]];
        yield 'null' => [[null]];
        yield 'mixed' => [['auth_users', true]];
    }

    public function testEnableCacheAcceptsTagsWhenValidationPasses(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->storeValidator->expects('validateAnyModeTags')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']');

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertTrue($provider->isCacheEnabled());
    }

    public function testEnableCacheRejectsTagsWithAllModeStore(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->storeValidator->expects('validateAnyModeTags')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->throws(new InvalidArgumentException('TagMode::Any is required.'));

        $provider = $this->providerWithoutDbFetch();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TagMode::Any/');

        $provider->enableCache(null, tags: ['auth_users']);
    }

    #[DataProvider('nonTaggableWhitelistedStoreProvider')]
    public function testEnableCacheRejectsTagsWithNonTaggableStore(string $storeClass): void
    {
        $repo = $this->stubCache($storeClass);
        $this->storeValidator->expects('validateAnyModeTags')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->throws(new InvalidArgumentException('The cache store does not support tags.'));

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

    public function testRetrieveByIdMissUsesTaggedRepoForPutWhenTagsConfigured(): void
    {
        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = Double::for(TaggedCache::class);
        $user = Double::for(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->expects('tags')->with(['auth_users'])->returns($taggedRepo);
        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $taggedRepo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testRetrieveByIdUsesTaggedRepoWhenTagsConfigured(): void
    {
        $plainRepo = $this->stubCache(RedisStore::class);
        $user = Double::for(Authenticatable::class);

        $plainRepo->expects('tags')->never();
        $this->expectCoordinatorFill($plainRepo, $this->buildDefaultKey(42), $user);

        $provider = $this->providerWithoutDbFetch();
        $provider->enableCache(null, tags: ['auth_users']);

        $this->assertSame($user, $provider->retrieveById(42));
    }

    public function testClearUserCacheUsesPlainRepoEvenWhenTagsConfigured(): void
    {
        $plainRepo = $this->stubCache(RedisStore::class);

        $this->cacheCoordinator->expects('invalidate')
            ->with(Argument::same($plainRepo), $this->buildDefaultKey(42));
        $plainRepo->expects('tags')->never();

        $provider = $this->providerForCacheClear();
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->clearUserCache(42);
    }

    public function testEffectiveTagsCombineStaticAndDynamic(): void
    {
        EloquentUserProvider::resolveUserCacheTagsUsing(fn (): array => ['scope:a']);

        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = Double::for(TaggedCache::class);
        $user = Double::for(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->expects('tags')->with(['auth_users', 'scope:a'])->returns($taggedRepo);
        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $taggedRepo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
    }

    public function testEffectiveTagsAreJustStaticWhenNoResolver(): void
    {
        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo = Double::for(TaggedCache::class);
        $user = Double::for(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $plainRepo->expects('tags')->with(['auth_users'])->returns($taggedRepo);
        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $taggedRepo);

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
    }

    public function testDynamicResolverIsInvokedFreshlyOnEachPut(): void
    {
        $count = 0;
        EloquentUserProvider::resolveUserCacheTagsUsing(function () use (&$count): array {
            ++$count;

            return ['scope:' . $count];
        });

        $plainRepo = $this->stubCache(RedisStore::class);
        $taggedRepo1 = Double::for(TaggedCache::class);
        $taggedRepo2 = Double::for(TaggedCache::class);
        $user1 = Double::for(Authenticatable::class);
        $user2 = Double::for(Authenticatable::class);
        $key1 = $this->buildDefaultKey(42);
        $key2 = $this->buildDefaultKey(43);

        $plainRepo->expects('tags')->with(['auth_users', 'scope:1'])->returns($taggedRepo1);
        $plainRepo->expects('tags')->with(['auth_users', 'scope:2'])->returns($taggedRepo2);
        $this->expectCoordinatorFill($plainRepo, $key1, $user1, readSource: true, expectedWriter: $taggedRepo1);
        $this->expectCoordinatorFill($plainRepo, $key2, $user2, readSource: true, expectedWriter: $taggedRepo2);

        // Set up two DB fetches with distinct models/IDs.
        $model1 = Double::for(User::class);
        $query1 = Double::for(QueryBuilder::class);
        $builder1 = Double::for(new Builder($query1))->passthru();
        $model1->expects('newQuery')->returns($builder1);
        $model1->expects('getAuthIdentifierName')->returns('id');
        $query1->expects('useWritePdo');
        $builder1->expects('where')->with('id', 42)->returns($builder1);
        $builder1->expects('first')->returns($user1);

        $model2 = Double::for(User::class);
        $query2 = Double::for(QueryBuilder::class);
        $builder2 = Double::for(new Builder($query2))->passthru();
        $model2->expects('newQuery')->returns($builder2);
        $model2->expects('getAuthIdentifierName')->returns('id');
        $query2->expects('useWritePdo');
        $builder2->expects('where')->with('id', 43)->returns($builder2);
        $builder2->expects('first')->returns($user2);

        $provider = $this->providerMock();
        $provider->expects($this->exactly(2))->method('createModel')->willReturnOnConsecutiveCalls($model1, $model2);
        $provider->enableCache(null, tags: ['auth_users']);

        $provider->retrieveById(42);
        $provider->retrieveById(43);

        $this->assertSame(2, $count);
    }

    public function testDynamicResolverIgnoredWhenNoStaticTagsConfigured(): void
    {
        $resolverInvoked = false;
        EloquentUserProvider::resolveUserCacheTagsUsing(function () use (&$resolverInvoked): array {
            $resolverInvoked = true;

            return ['scope:a'];
        });

        $plainRepo = $this->stubCache(RedisStore::class);
        $user = Double::for(Authenticatable::class);
        $key = $this->buildDefaultKey(42);

        $this->expectCoordinatorFill($plainRepo, $key, $user, readSource: true, expectedWriter: $plainRepo);
        $plainRepo->expects('tags')->never();

        $provider = $this->providerExpectingDbFetch($user, 42);
        $provider->enableCache(null);

        $provider->retrieveById(42);

        $this->assertFalse($resolverInvoked);
    }

    public function testFlushStateClearsTagsResolver(): void
    {
        EloquentUserProvider::resolveUserCacheTagsUsing(fn (): array => ['scope:a']);

        $reflection = new ReflectionClass(EloquentUserProvider::class);
        $this->assertNotNull($reflection->getStaticPropertyValue('cacheTagsResolver'));

        EloquentUserProvider::flushState();

        $this->assertNull($reflection->getStaticPropertyValue('cacheTagsResolver'));
    }

    public function testEnableCacheLeavesProviderInDisabledStateWhenTagValidationFails(): void
    {
        $repo = $this->stubCache(RedisStore::class);
        $this->storeValidator->expects('validateAnyModeTags')
            ->with(Argument::same($repo), 'Auth user cache for model [' . self::MODEL . ']')
            ->throws(new InvalidArgumentException('TagMode::Any is required.'));

        $user = Double::for(Authenticatable::class);
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

    public function testRecallingEnableCacheWithoutTagsClearsPreviousTagState(): void
    {
        // First call uses a Redis store in any-mode (tag-valid), second
        // call uses a plain Redis store (no tags). Set up both upfront so
        // the cache manager returns them in sequence from store(null).
        $store1 = Double::for(RedisStore::class);
        $repo1 = Double::for(CacheRepository::class);
        $repo1->allows('getStore')->returns($store1);

        $store2 = Double::for(RedisStore::class);
        $repo2 = Double::for(CacheRepository::class);
        $repo2->allows('getStore')->returns($store2);

        $this->cacheManager->allows('store')->with(null)->returns($repo1, $repo2);

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
        $hasher = Double::for(Hasher::class);

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
        $model = Double::for(User::class);
        $query = Double::for(QueryBuilder::class);
        $builder = Double::for(new Builder($query))->passthru();
        $model->expects('newQuery')->returns($builder);
        $model->expects('getAuthIdentifierName')->returns('id');
        if ($useWritePdo) {
            $query->expects('useWritePdo');
        } else {
            $query->expects('useWritePdo')->never();
        }
        $builder->expects('where')->with('id', $id)->returns($builder);
        $builder->expects('first')->returns($user);

        $provider = $this->providerMock();
        $provider->expects($this->once())->method('createModel')->willReturn($model);

        return $provider;
    }

    /**
     * Create a provider with a committed model connection for manual invalidation.
     */
    protected function providerForCacheClear(): EloquentUserProvider&MockObject
    {
        $connection = Double::for(Connection::class);
        $connection->expects('afterCommitOrNow')
            ->with(Argument::type(Closure::class))
            ->resolves(static fn (Closure $callback): mixed => $callback());

        $model = Double::for(User::class);
        $model->expects('getConnection')->returns($connection);

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
        $this->cacheCoordinator->expects('fill')
            ->with(
                Argument::same($repository),
                Argument::type(Closure::class),
                $key,
                300,
                Argument::type(Closure::class),
                true,
                Argument::type(Closure::class),
            )
            ->resolves(function (
                CacheRepository $cache,
                Closure $source,
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
     * Create a provider that must not construct a source model.
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
    protected function stubCache(string $storeClass, ?string $name = null): Repository&DoubleInterface
    {
        $store = Double::for($storeClass);

        $repo = Double::for(Repository::class);
        $repo->allows('getStore')->returns($store);
        $this->cacheManager->allows('store')->with($name)->returns($repo);

        return $repo;
    }

    protected function buildDefaultKey(mixed $identifier): string
    {
        return self::DEFAULT_KEY_PREFIX . ':' . self::MODEL . ':' . $identifier;
    }
}

class EloquentCacheProviderUserStub extends User
{
}

class EloquentCacheProviderAlternateUserStub extends User
{
}
