<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

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
use Hypervel\JWT\Blacklist;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Contracts\StorageContract;
use Hypervel\JWT\Http\Parser\Cookie;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\JWT\JwtGuard;
use Hypervel\JWT\JWTServiceProvider;
use Hypervel\JWT\Storage\TaggedCache;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;

class JWTServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            JWTServiceProvider::class,
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

    public function testGuardReceivesExplicitNullTtlAndDispatcher(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.defaults.guard', 'jwt');
        $config->set('auth.guards.jwt', [
            'driver' => 'jwt',
            'provider' => 'users',
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

    public function testTaggedCacheStorageUsesCacheStore(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', TaggedCache::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.blacklist_refresh_ttl', 20160);

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
        $config->set('jwt.blacklist_refresh_ttl', 20160);

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
        $config->set('jwt.blacklist_refresh_ttl', 20160);

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
        $config->set('jwt.blacklist_refresh_ttl', 20160);

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
        $config->set('jwt.blacklist_refresh_ttl', 20160);

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
            . 'Use a taggable store or set a custom jwt.providers.storage.'
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
        $config->set('jwt.blacklist_refresh_ttl', 20160);

        $cache = m::mock();
        $cache->shouldReceive('store')->never();

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
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

class JwtServiceProviderCustomStorage implements StorageContract
{
    public function add(string $key, mixed $value, int $minutes): void
    {
    }

    public function forever(string $key, mixed $value): void
    {
    }

    public function get(string $key): mixed
    {
        return null;
    }

    public function destroy(string $key): bool
    {
        return true;
    }

    public function flush(): void
    {
    }
}
