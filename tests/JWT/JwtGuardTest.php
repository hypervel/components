<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\AuthServiceProvider;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\JwtGuard;
use Hypervel\JWT\JWTServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class JwtGuardTest extends TestCase
{
    public function testParseTokenFromBearerHeader()
    {
        $guard = $this->createGuard(
            request: $this->createRequestWithBearer('test-token')
        );

        $this->assertSame('test-token', $guard->parseToken());
    }

    public function testParseTokenFromRequestInput()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('header')->with('Authorization', '')->andReturn('');
        $request->shouldReceive('has')->with('token')->andReturnTrue();
        $request->shouldReceive('input')->with('token')->andReturn('input-token');

        $guard = $this->createGuard(request: $request);

        $this->assertSame('input-token', $guard->parseToken());
    }

    public function testParseTokenReturnsNullWhenNoRequestContext()
    {
        // Remove the request from context so RequestContext::has() returns false
        RequestContext::forget();

        $guard = $this->createGuard(request: null);

        $this->assertNull($guard->parseToken());
    }

    public function testUserReturnsUserFromJwtPayload()
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(42)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 42]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($user, $guard->user());
    }

    public function testUserReturnsNullWhenNoToken()
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->user());
    }

    public function testUserCachesResultInContext()
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(42)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 42]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->user()); // Should not call decode again
    }

    public function testUserCachesNullViaSentinel()
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(42)->once()->andReturn(null);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 42]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertNull($guard->user());
        $this->assertNull($guard->user()); // Should not call decode again
    }

    public function testAttemptReturnsTrueOnValidCredentials()
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')
            ->with(['email' => 'foo@bar.com', 'password' => 'secret'])
            ->andReturn($user);
        $provider->shouldReceive('validateCredentials')->with($user, m::type('array'))->andReturnTrue();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->attempt(['email' => 'foo@bar.com', 'password' => 'secret']));
    }

    public function testAttemptReturnsFalseOnInvalidCredentials()
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->andReturn(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->attempt(['email' => 'foo@bar.com', 'password' => 'wrong']));
    }

    public function testValidateDoesNotLoginUser()
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->andReturn($user);
        $provider->shouldReceive('validateCredentials')->andReturnTrue();

        $jwtManager = m::mock(ManagerContract::class);
        // encode should still be called because validate calls attempt(credentials, true)
        // actually validate calls attempt(credentials, false)
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->validate(['email' => 'foo@bar.com', 'password' => 'secret']));
    }

    public function testLoginReturnsToken()
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('jwt-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $token = $guard->login($user);

        $this->assertSame('jwt-token', $token);
    }

    public function testLoginPayloadContainsSubIatExp()
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $capturedPayload = null;
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturnUsing(function ($payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return 'token';
        });

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $guard->login($user);

        $this->assertSame(42, $capturedPayload['sub']);
        $this->assertArrayHasKey('iat', $capturedPayload);
        $this->assertArrayHasKey('exp', $capturedPayload);
        $this->assertGreaterThan($capturedPayload['iat'], $capturedPayload['exp']);
    }

    public function testClaimsMergeIntoNextToken()
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $capturedPayload = null;
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturnUsing(function ($payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return 'token';
        });

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $guard->claims(['role' => 'admin', 'org' => 'acme']);
        $guard->login($user);

        $this->assertSame('admin', $capturedPayload['role']);
        $this->assertSame('acme', $capturedPayload['org']);
    }

    public function testGetPayloadReturnsDecodedToken()
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1, 'iat' => 1000]);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $payload = $guard->getPayload();

        $this->assertSame(['sub' => 1, 'iat' => 1000], $payload);
    }

    public function testGetPayloadReturnsEmptyArrayWhenNoToken()
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertSame([], $guard->getPayload());
    }

    public function testRefreshDelegatesAndClearsContext()
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')->with('old-token')->once()->andReturn('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->refresh());
    }

    public function testRefreshReturnsNullWhenNoToken()
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->refresh());
    }

    public function testLogoutInvalidatesTokenAndClearsContext()
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('invalidate')->with('valid-token')->once()->andReturnTrue();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        // Resolve user first
        $this->assertSame($user, $guard->user());

        $guard->logout();

        // After logout, hasUser should be false
        $this->assertFalse($guard->hasUser());
    }

    public function testHasUserReturnsTrueAfterUserResolved()
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->andReturn(['sub' => 1]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->user();

        $this->assertTrue($guard->hasUser());
    }

    public function testHasUserReturnsFalseBeforeResolution()
    {
        $guard = $this->createGuard(
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertFalse($guard->hasUser());
    }

    public function testSetUserOverridesCachedUser()
    {
        $user1 = m::mock(Authenticatable::class);
        $user2 = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->andReturn(['sub' => 1]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->user();
        $guard->setUser($user2);

        $this->assertSame($user2, $guard->user());
    }

    public function testForgetUserClearsCache()
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->andReturn(['sub' => 1]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->user();
        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
    }

    public function testOnceUsingIdReturnsTrueWhenUserExists()
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->andReturn('token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->onceUsingId(1));
    }

    public function testOnceUsingIdReturnsFalseWhenUserNotFound()
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(999)->andReturn(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->onceUsingId(999));
    }

    public function testDecodedPayloadIsCachedBetweenUserAndGetPayload()
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn(
            m::mock(Authenticatable::class)
        );

        $jwtManager = m::mock(ManagerContract::class);
        // decode() should be called exactly once — the second call uses the cache
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn([
            'sub' => 1,
            'iat' => 1000,
            'exp' => 9999999999,
        ]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        // First call — decodes the token
        $user = $guard->user();
        $this->assertNotNull($user);

        // Second call — should use cached payload, not decode again
        $payload = $guard->getPayload();
        $this->assertSame(1, $payload['sub']);
    }

    public function testServiceProviderRegistersJwtGuardWhenAuthManagerResolvesAfterBoot()
    {
        $provider = m::mock(UserProvider::class);
        $container = $this->createAuthTestContainer();

        $jwtServiceProvider = new JWTServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', m::mock(ManagerContract::class));
        $jwtServiceProvider->boot();

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn ($app, $config) => $provider);

        $this->assertInstanceOf(JwtGuard::class, $authManager->guard('jwt'));
    }

    public function testServiceProviderRegistersJwtGuardWhenAuthManagerIsAlreadyResolved()
    {
        $provider = m::mock(UserProvider::class);
        $container = $this->createAuthTestContainer();

        $jwtServiceProvider = new JWTServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', m::mock(ManagerContract::class));

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn ($app, $config) => $provider);

        $jwtServiceProvider->boot();

        $this->assertInstanceOf(JwtGuard::class, $authManager->guard('jwt'));
    }

    /**
     * Create a JwtGuard instance for testing.
     */
    protected function createGuard(
        ?UserProvider $provider = null,
        ?ManagerContract $jwtManager = null,
        ?Request $request = null,
        int $ttl = 120,
    ): JwtGuard {
        $container = new Container;

        if ($request !== null) {
            $container->instance('request', $request);
            // Set RequestContext so parseToken() works
            CoroutineContext::set(Request::class, $request);
        }

        return new JwtGuard(
            'jwt',
            $provider ?? m::mock(UserProvider::class),
            $jwtManager ?? m::mock(ManagerContract::class),
            $container,
            $ttl,
        );
    }

    /**
     * Create a request mock with a Bearer token.
     */
    protected function createRequestWithBearer(?string $token): Request
    {
        $request = m::mock(Request::class);

        if ($token !== null) {
            $request->shouldReceive('header')
                ->with('Authorization', '')
                ->andReturn("Bearer {$token}");
        } else {
            $request->shouldReceive('header')
                ->with('Authorization', '')
                ->andReturn('');
            $request->shouldReceive('has')->with('token')->andReturnFalse();
        }

        return $request;
    }

    protected function createAuthTestContainer(): Application
    {
        $container = new Application;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => [
                    'guard' => 'jwt',
                    'provider' => 'users',
                ],
                'guards' => [
                    'jwt' => [
                        'driver' => 'jwt',
                        'provider' => 'users',
                    ],
                ],
                'providers' => [
                    'users' => [
                        'driver' => 'jwt-test-provider',
                    ],
                ],
            ],
            'jwt' => [
                'ttl' => 120,
            ],
        ]));

        (new AuthServiceProvider($container))->register();
        $container->alias('auth', AuthManager::class);

        return $container;
    }
}
