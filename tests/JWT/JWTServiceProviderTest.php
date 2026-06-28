<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\JWT\Blacklist;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Http\Middleware\AuthenticateAndRenew;
use Hypervel\JWT\Http\Middleware\RefreshToken;
use Hypervel\JWT\Http\Parser\Cookie;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\JWT\JwtGuard;
use Hypervel\JWT\JWTServiceProvider;
use Hypervel\JWT\Storage\TaggedCache;
use Hypervel\Testbench\TestCase;
use Mockery as m;

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

    public function testMiddlewareAliasesAreRegistered(): void
    {
        $middleware = $this->app->make('router')->getMiddleware();

        $this->assertSame(RefreshToken::class, $middleware['jwt.refresh']);
        $this->assertSame(AuthenticateAndRenew::class, $middleware['jwt.renew']);
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
        $config->set('jwt.blacklist_grace_period', 0);
        $config->set('jwt.blacklist_refresh_ttl', 20160);

        $repository = m::mock(CacheRepository::class);
        $cache = m::mock();
        $cache->shouldReceive('store')->once()->withNoArgs()->andReturn($repository);

        $this->app->instance('cache', $cache);
        $this->app->forgetInstance(BlacklistContract::class);

        $blacklist = $this->app->make(BlacklistContract::class);

        $this->assertInstanceOf(Blacklist::class, $blacklist);
    }
}
