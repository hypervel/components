<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Auth\AuthManager;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Jwt\Blacklist;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\ManagerContract;
use Hypervel\Jwt\Contracts\StorageContract;
use Hypervel\Jwt\Contracts\TokenExtractor;
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\Cookie;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\JwtGuard;
use Hypervel\Jwt\JwtServiceProvider;
use Hypervel\Jwt\Providers\Lcobucci;
use Hypervel\Jwt\Storage\CacheStorage;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

class JwtServiceProviderTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            JwtServiceProvider::class,
        ];
    }

    public function testParserUsesSeparateInputAndCookieNames(): void
    {
        config([
            'jwt.token' => 'api_token',
            'jwt.cookie_key_name' => 'jwt_cookie',
            'jwt.parser' => [InputSource::class, Cookie::class],
        ]);

        /** @var Parser $parser */
        $parser = $this->app->make(Parser::class);

        $this->assertSame('input-token', $parser->parseToken(Request::create('/?api_token=input-token')));
        $this->assertSame('cookie-token', $parser->parseToken(Request::create('/', 'GET', cookies: [
            'jwt_cookie' => 'cookie-token',
        ])));
        $this->assertNull($parser->parseToken(Request::create('/?jwt_cookie=input-token', 'GET', cookies: [
            'api_token' => 'cookie-token',
        ])));
    }

    public function testParserResolvesCustomExtractorsFromTheContainer(): void
    {
        config([
            'jwt.parser' => [AuthHeaders::class, JwtServiceProviderAccessTokenHeader::class],
        ]);

        /** @var Parser $parser */
        $parser = $this->app->make(Parser::class);

        $this->assertSame('custom-token', $parser->parseToken(Request::create('/', 'GET', server: [
            'HTTP_X_ACCESS_TOKEN' => 'custom-token',
        ])));
        $this->assertNull($parser->parseToken(Request::create('/')));
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

    public function testGuardRejectsUnsupportedTtlValue(): void
    {
        $this->app->make('config')->set('auth.guards.customers', [
            'driver' => 'jwt',
            'provider' => 'users',
            'ttl' => 'forever',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            'JWT TTL for auth guard [customers] must be an integer or null.'
        );

        $this->app->make(AuthManager::class)->guard('customers');
    }

    #[DataProvider('blacklistStoreProvider')]
    public function testCacheStorageUsesTheConfiguredBlacklistStore(?string $store): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', CacheStorage::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_store', $store);

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('getStore')->once()->andReturn(m::mock(Store::class));
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->with($store)->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);
        $storage = (new ReflectionProperty($blacklist, 'storage'))->getValue($blacklist);

        $this->assertInstanceOf(CacheStorage::class, $storage);
        $this->assertSame($repository, (new ReflectionProperty($storage, 'cache'))->getValue($storage));
    }

    /**
     * Provide blacklist cache store settings.
     *
     * @return array<string, array{null|string}>
     */
    public static function blacklistStoreProvider(): array
    {
        return [
            'default store' => [null],
            'named store' => ['redis'],
        ];
    }

    public function testCustomBlacklistStorageSkipsCacheStoreResolution(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers.storage', JwtServiceProviderCustomStorage::class);
        $config->set('jwt.blacklist_enabled', true);
        $config->set('jwt.blacklist_store', 'redis');
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

    public function testProviderImplementationsUsePackageDefaultsWhenOmitted(): void
    {
        $config = $this->app->make('config');
        $config->set('jwt.providers', []);
        $config->set('jwt.secret', 'test-secret');
        $config->set('jwt.blacklist_enabled', false);
        $this->app->forgetInstance(BlacklistContract::class);
        $this->app->forgetInstance('jwt');

        $manager = $this->app->make('jwt');
        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Lcobucci::class, $manager->driver());
        $this->assertInstanceOf(
            CacheStorage::class,
            (new ReflectionProperty($blacklist, 'storage'))->getValue($blacklist),
        );
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

class JwtServiceProviderAccessTokenHeader implements TokenExtractor
{
    /**
     * Read the token from the custom header.
     */
    public function parseToken(Request $request): ?string
    {
        return $request->headers->get('X-Access-Token');
    }
}
