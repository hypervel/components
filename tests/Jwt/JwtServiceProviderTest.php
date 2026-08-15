<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use ErrorException;
use Hypervel\Auth\AuthManager;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TagMode;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Jwt\Blacklist;
use Hypervel\Jwt\ClaimFactory;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\ManagerContract;
use Hypervel\Jwt\Contracts\ProviderContract;
use Hypervel\Jwt\Contracts\StorageContract;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\JwtGuard;
use Hypervel\Jwt\JwtManager;
use Hypervel\Jwt\JwtServiceProvider;
use Hypervel\Jwt\Providers\Lcobucci;
use Hypervel\Jwt\Storage\TaggedCache;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;

class JwtServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            JwtServiceProvider::class,
        ];
    }

    public function testParserUsesConfiguredTokenKeyAndExtractorChain(): void
    {
        $this->app->make('config')->set('jwt.token', 'api_token');
        $this->app->make('config')->set('jwt.parser', [Cookie::class]);

        /** @var Parser $parser */
        $parser = $this->app->make(Parser::class);

        $this->assertSame('cookie-token', $parser->parseToken(Request::create('/', 'GET', cookies: [
            'api_token' => 'cookie-token',
        ])));
    }

    public function testJwtMiddlewareAliasesAreNotRegistered(): void
    {
        $middleware = $this->app->make('router')->getMiddleware();

        $this->assertArrayNotHasKey('jwt.refresh', $middleware);
        $this->assertArrayNotHasKey('jwt.renew', $middleware);
        $this->assertArrayNotHasKey('jwt.auth', $middleware);
        $this->assertArrayNotHasKey('jwt.check', $middleware);
    }

    public function testShippedJwtGuardInheritsGlobalTtl(): void
    {
        $this->app->make('config')->set('jwt.ttl', 45);

        /** @var JwtGuard $guard */
        $guard = $this->app->make(AuthManager::class)->guard('jwt');

        $this->assertSame(45, $guard->getTTL());
    }

    public function testGuardReceivesExplicitNullTtlAndDispatcher(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.defaults.guard', 'jwt');
        $config->set('auth.guards.jwt', [
            'driver' => 'jwt',
            'provider' => 'users',
            'passwords' => null,
            'password_timeout' => null,
            'ttl' => null,
        ]);
        $config->set('auth.providers.users', [
            'driver' => 'jwt-test-provider',
        ]);

        $this->app->instance('jwt', m::mock(ManagerContract::class));

        /** @var AuthManager $authManager */
        $authManager = $this->app->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn () => m::mock(UserProvider::class));

        /** @var JwtGuard $guard */
        $guard = $authManager->guard('jwt');

        $this->assertNull($guard->getTTL());
        $this->assertSame($this->app->make('events'), $guard->getDispatcher());
    }

    public function testGuardReceivesNumericPerGuardTtl(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.defaults.guard', 'jwt');
        $config->set('auth.guards.jwt', [
            'driver' => 'jwt',
            'provider' => 'users',
            'passwords' => null,
            'password_timeout' => null,
            'ttl' => 15,
        ]);
        $config->set('auth.providers.users', [
            'driver' => 'jwt-test-provider',
        ]);

        $this->app->instance('jwt', m::mock(ManagerContract::class));

        /** @var AuthManager $authManager */
        $authManager = $this->app->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn () => m::mock(UserProvider::class));

        /** @var JwtGuard $guard */
        $guard = $authManager->guard('jwt');

        $this->assertSame(15, $guard->getTTL());
    }

    public function testGuardRequiresTtlMember(): void
    {
        $this->app->make('config')->set('auth.guards.jwt', [
            'driver' => 'jwt',
            'provider' => 'users',
            'passwords' => null,
            'password_timeout' => null,
        ]);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "ttl"');

        $this->app->make(AuthManager::class)->guard('jwt');
    }

    public function testGuardRejectsUnsupportedTtlValue(): void
    {
        $this->app->make('config')->set('auth.guards.customers', [
            'driver' => 'jwt',
            'provider' => 'users',
            'passwords' => null,
            'password_timeout' => null,
            'ttl' => 'forever',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Auth guard [customers] declares an invalid jwt ttl. Use an integer, null, or 'inherit'."
        );

        $this->app->make(AuthManager::class)->guard('customers');
    }

    public function testTaggedCacheStorageUsesCacheStore(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.refresh_ttl', 20160);

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $repository->shouldReceive('getStore')->once()->andReturn($this->taggableStore(TagMode::All));
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }

    public function testTaggedCacheStorageAcceptsAnyModeCacheStore(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.refresh_ttl', 20160);

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $repository->shouldReceive('getStore')->once()->andReturn($this->taggableStore(TagMode::Any));
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }

    public function testTaggedCacheStorageAcceptsValidStackCacheStore(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.refresh_ttl', 20160);

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $repository->shouldReceive('getStore')->once()->andReturn(new StackStore([
            new StackStoreProxy(m::mock(Store::class)),
            new StackStoreProxy($this->taggableStore(TagMode::Any)),
        ]));
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }

    public function testDisabledBlacklistAllowsNonTaggableCacheStore(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', false);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.refresh_ttl', 20160);

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('supportsTags')->never();
        $repository->shouldReceive('getStore')->once()->andReturn(m::mock(Store::class));
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }

    public function testDisabledBlacklistAllowsInvalidTaggableCacheStore(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', false);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.refresh_ttl', 20160);

        $store = m::mock(TaggableStore::class);
        $store->shouldReceive('supportsTags')->once()->andReturnFalse();
        $store->shouldReceive('getTagMode')->never();

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('supportsTags')->never();
        $repository->shouldReceive('getStore')->once()->andReturn($store);
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }

    public function testEnabledTaggedCacheBlacklistRequiresTaggableCacheStore(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The JWT blacklist requires a taggable cache store (all-mode or any-mode). '
            . 'Use a taggable store or configure a custom ' . StorageContract::class
            . ' implementation in jwt.providers.storage.'
        );

        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', true);

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $this->app->make(BlacklistContract::class);
    }

    public function testCustomBlacklistStorageBypassesTaggedCacheRequirement(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', JwtServiceProviderCustomStorage::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.refresh_ttl', 20160);

        $cache = m::mock();
        $cache->shouldReceive('store')->never();

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }

    public function testBlacklistReceivesFiniteRefreshTtlAndLeeway(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', JwtServiceProviderCustomStorage::class);
        $config->set('jwt.refresh_ttl', 5);
        $config->set('jwt.leeway', 120);

        $this->app->forgetInstance(BlacklistContract::class);

        /** @var Blacklist $blacklist */
        $blacklist = $this->app->make(BlacklistContract::class);
        $storage = $this->app->make(JwtServiceProviderCustomStorage::class);
        $now = Date::now()->timestamp;

        $this->assertSame(5, $blacklist->getRefreshTTL());
        $this->assertTrue($blacklist->add([
            'exp' => $now + 600,
            'iat' => $now,
            'jti' => 'foo',
        ]));
        $this->assertSame(13, $storage->minutes);
    }

    public function testBlacklistReceivesNullRefreshTtl(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', JwtServiceProviderCustomStorage::class);
        $config->set('jwt.refresh_ttl', null);

        $this->app->forgetInstance(BlacklistContract::class);

        /** @var Blacklist $blacklist */
        $blacklist = $this->app->make(BlacklistContract::class);
        $storage = $this->app->make(JwtServiceProviderCustomStorage::class);

        $this->assertNull($blacklist->getRefreshTTL());
        $this->assertTrue($blacklist->add([
            'exp' => Date::now()->timestamp + 600,
            'iat' => Date::now()->timestamp,
            'jti' => 'foo',
        ]));
        $this->assertTrue($storage->foreverCalled);
    }

    public function testStorageProviderIsRequired(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers', ['jwt' => Lcobucci::class]);
        $config->set('jwt.blacklist_enabled', true);
        $this->app->forgetInstance(BlacklistContract::class);
        $this->app->forgetInstance('jwt');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [jwt.providers.storage] must be a string');

        $this->app->make('jwt');
    }

    public function testReloadConfigurationRefreshesResolvedJwtServices(): void
    {
        $config = $this->app->make('config');
        $config->set([
            'jwt.issuer' => 'old-issuer',
            'jwt.lock_subject' => false,
            'jwt.blacklist_enabled' => false,
            'jwt.driver' => 'reload-test',
            'jwt.token' => 'old_token',
            'jwt.parser' => [Cookie::class],
            'jwt.providers.storage' => JwtServiceProviderCustomStorage::class,
            'jwt.blacklist_grace_period' => 0,
            'jwt.refresh_ttl' => 60,
            'jwt.leeway' => 0,
        ]);

        $claimFactory = $this->app->make(ClaimFactory::class);
        $parser = $this->app->make(Parser::class);
        $manager = $this->app->make('jwt');
        $manager->extend('reload-test', static fn (): ProviderContract => new JwtServiceProviderDriverStub);
        $driver = $manager->driver();

        $this->assertSame('old-token', $parser->parseToken(Request::create('/', 'GET', cookies: [
            'old_token' => 'old-token',
        ])));

        $config->set([
            'jwt.issuer' => 'new-issuer',
            'jwt.lock_subject' => true,
            'jwt.blacklist_enabled' => true,
            'jwt.token' => 'new_token',
        ]);

        $this->app->getProvider(JwtServiceProvider::class)->reloadConfiguration();

        $issuer = new ReflectionProperty(ClaimFactory::class, 'issuer');
        $lockSubject = new ReflectionProperty(ClaimFactory::class, 'lockSubject');
        $this->assertSame($claimFactory, $this->app->make(ClaimFactory::class));
        $this->assertSame('new-issuer', $issuer->getValue($claimFactory));
        $this->assertTrue($lockSubject->getValue($claimFactory));
        $this->assertNotSame($parser, $this->app->make(Parser::class));
        $this->assertSame('new-token', $this->app->make(Parser::class)->parseToken(Request::create('/', 'GET', cookies: [
            'new_token' => 'new-token',
        ])));
        $this->assertSame($manager, $this->app->make(JwtManager::class));
        $this->assertTrue($manager->hasBlacklistEnabled());
        $this->assertNotSame($driver, $manager->driver());
        $this->assertInstanceOf(Blacklist::class, $this->app->make(BlacklistContract::class));
    }

    public function testReloadConfigurationDoesNotResolveUnusedJwtServices(): void
    {
        $provider = $this->app->getProvider(JwtServiceProvider::class);

        $this->assertFalse($this->app->resolved(ClaimFactory::class));
        $this->assertFalse($this->app->resolved(Parser::class));
        $this->assertFalse($this->app->resolved(BlacklistContract::class));
        $this->assertFalse($this->app->resolved('jwt'));

        $provider->reloadConfiguration();

        $this->assertFalse($this->app->resolved(ClaimFactory::class));
        $this->assertFalse($this->app->resolved(Parser::class));
        $this->assertFalse($this->app->resolved(BlacklistContract::class));
        $this->assertFalse($this->app->resolved('jwt'));
    }

    protected function taggableStore(TagMode $mode): TaggableStore
    {
        /** @var TaggableStore $store */
        $store = m::mock(TaggableStore::class);
        $store->shouldReceive('supportsTags')->zeroOrMoreTimes()->andReturnTrue();
        $store->shouldReceive('getTagMode')->once()->andReturn($mode);

        return $store;
    }
}

class JwtServiceProviderDriverStub implements ProviderContract
{
    public function encode(array $payload): string
    {
        return '';
    }

    public function decode(string $token): array
    {
        return [];
    }
}

class JwtServiceProviderCustomStorage implements StorageContract
{
    public ?int $minutes = null;

    public bool $foreverCalled = false;

    public function add(string $key, mixed $value, int $minutes): bool
    {
        $this->minutes = $minutes;

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->foreverCalled = true;

        return true;
    }

    public function get(string $key): mixed
    {
        return null;
    }

    public function destroy(string $key): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }
}
