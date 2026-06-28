<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\AuthServiceProvider;
use Hypervel\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\JWT\ClaimFactory;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\JWT\Exceptions\SecretMissingException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\Exceptions\UserNotDefinedException;
use Hypervel\JWT\Http\Parser\AuthHeaders;
use Hypervel\JWT\Http\Parser\InputSource;
use Hypervel\JWT\Http\Parser\Parser;
use Hypervel\JWT\JwtGuard;
use Hypervel\JWT\JWTServiceProvider;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class JwtGuardTest extends TestCase
{
    public function testParseTokenFromBearerHeader(): void
    {
        $guard = $this->createGuard(
            request: $this->createRequestWithBearer('test-token')
        );

        $this->assertSame('test-token', $guard->parseToken());
    }

    public function testParseTokenFromRequestInput(): void
    {
        $guard = $this->createGuard(request: Request::create('/', 'GET', ['token' => 'input-token']));

        $this->assertSame('input-token', $guard->parseToken());
    }

    public function testParseTokenReturnsNullWhenNoRequestContext(): void
    {
        // Remove the request from context so RequestContext::has() returns false
        RequestContext::forget();

        $guard = $this->createGuard(request: null);

        $this->assertNull($guard->parseToken());
    }

    public function testUserReturnsUserFromJwtPayload(): void
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

    public function testUserReturnsNullWhenNoToken(): void
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->user());
    }

    public function testUserCachesResultInContext(): void
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

    public function testUserCachesNullViaSentinel(): void
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

    public function testAttemptReturnsTokenOnValidCredentials(): void
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

        $this->assertSame('new-token', $guard->attempt(['email' => 'foo@bar.com', 'password' => 'secret']));
    }

    public function testAttemptReturnsFalseOnInvalidCredentials(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->andReturn(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->attempt(['email' => 'foo@bar.com', 'password' => 'wrong']));
    }

    public function testValidateDoesNotLoginUser(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->andReturn($user);
        $provider->shouldReceive('validateCredentials')->andReturnTrue();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->validate(['email' => 'foo@bar.com', 'password' => 'secret']));
    }

    public function testAttemptWithoutLoginReturnsTrueAndDoesNotMintToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnTrue();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->attempt(['email' => 'foo@bar.com', 'password' => 'secret'], false));
    }

    public function testLoginReturnsToken(): void
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
        $this->assertSame('jwt-token', $guard->getToken());
        $this->assertSame($user, $guard->user());
    }

    public function testLoginOverridesExistingRequestToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');
        $jwtManager->shouldNotReceive('decode');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->login($user));
        $this->assertSame('new-token', $guard->getToken());
        $this->assertSame($user, $guard->user());
    }

    public function testLoginPayloadContainsSubIatExp(): void
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
        $this->assertArrayHasKey('nbf', $capturedPayload);
        $this->assertArrayHasKey('exp', $capturedPayload);
        $this->assertGreaterThan($capturedPayload['iat'], $capturedPayload['exp']);
    }

    public function testLoginOmitsExpirationWhenTtlIsNull(): void
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
            ttl: null,
        );

        $guard->login($user);

        $this->assertSame(42, $capturedPayload['sub']);
        $this->assertArrayHasKey('iat', $capturedPayload);
        $this->assertArrayHasKey('nbf', $capturedPayload);
        $this->assertArrayNotHasKey('exp', $capturedPayload);
    }

    public function testClaimsMergeIntoNextToken(): void
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

    public function testClaimsAreClearedAfterNextToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $capturedPayloads = [];
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->twice()->andReturnUsing(function ($payload) use (&$capturedPayloads) {
            $capturedPayloads[] = $payload;

            return 'token-' . count($capturedPayloads);
        });

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $guard->claims(['role' => 'admin'])->login($user);
        $guard->login($user);

        $this->assertSame('admin', $capturedPayloads[0]['role']);
        $this->assertArrayNotHasKey('role', $capturedPayloads[1]);
    }

    public function testSetTtlAppliesOnlyToNextToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $capturedPayloads = [];
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->twice()->andReturnUsing(function ($payload) use (&$capturedPayloads) {
            $capturedPayloads[] = $payload;

            return 'token-' . count($capturedPayloads);
        });

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $guard->setTTL(5)->login($user);
        $guard->login($user);

        $this->assertSame(300, $capturedPayloads[0]['exp'] - $capturedPayloads[0]['iat']);
        $this->assertSame(7200, $capturedPayloads[1]['exp'] - $capturedPayloads[1]['iat']);
    }

    public function testSetTtlNullAppliesOnlyToNextToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $capturedPayloads = [];
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->twice()->andReturnUsing(function ($payload) use (&$capturedPayloads) {
            $capturedPayloads[] = $payload;

            return 'token-' . count($capturedPayloads);
        });

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $guard->setTTL(null)->login($user);
        $guard->login($user);

        $this->assertArrayNotHasKey('exp', $capturedPayloads[0]);
        $this->assertArrayHasKey('exp', $capturedPayloads[1]);
    }

    public function testGetPayloadReturnsDecodedToken(): void
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

    public function testPayloadAliasesGetPayload(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1, 'iat' => 1000]);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame(['sub' => 1, 'iat' => 1000], $guard->payload());
    }

    public function testGetPayloadThrowsWhenPresentTokenIsInvalid(): void
    {
        $this->expectException(TokenInvalidException::class);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('invalid-token')->once()->andThrow(new TokenInvalidException);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('invalid-token'),
        );

        $guard->getPayload();
    }

    public function testGetPayloadReturnsEmptyArrayWhenNoToken(): void
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertSame([], $guard->getPayload());
    }

    public function testRefreshDelegatesAndClearsContext(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')->with('old-token', false, false, [], 120)->once()->andReturn('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->refresh());
    }

    public function testRefreshUsesPerCallTtlOverrideAndClearsIt(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')->with('old-token', false, false, [], 5)->once()->andReturn('new-token');
        $jwtManager->shouldReceive('refresh')->with('new-token', false, false, [], 120)->once()->andReturn('newer-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->setTTL(5)->refresh());
        $this->assertSame('newer-token', $guard->refresh());
    }

    public function testRefreshUsesNullTtlOverride(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')->with('old-token', false, false, [], null)->once()->andReturn('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->setTTL(null)->refresh());
    }

    public function testRefreshUsesPerGuardTtl(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')->with('old-token', false, false, [], 15)->once()->andReturn('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
            ttl: 15,
        );

        $this->assertSame('new-token', $guard->refresh());
    }

    public function testRefreshKeepsCachedUserUnderNewToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('old-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('refresh')->with('old-token', false, false, [], 120)->once()->andReturn('new-token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame($user, $guard->user());
        $this->assertSame('new-token', $guard->refresh());
        $this->assertSame($user, $guard->user());
    }

    public function testRefreshReturnsNullWhenNoToken(): void
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->refresh());
    }

    public function testLogoutInvalidatesTokenAndClearsContext(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')->with('valid-token', false)->once()->andReturnTrue();

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

    public function testLogoutClearsDecodedPayloadCache(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->twice()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnFalse();

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame(['sub' => 1], $guard->getPayload());

        $guard->logout();

        $this->assertSame(['sub' => 1], $guard->getPayload());
    }

    public function testLogoutPassesForceForeverFlag(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')->with('valid-token', true)->once()->andReturnTrue();

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->logout(true);
    }

    public function testHasUserReturnsTrueAfterUserResolved(): void
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

    public function testHasUserReturnsFalseBeforeResolution(): void
    {
        $guard = $this->createGuard(
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertFalse($guard->hasUser());
    }

    public function testGetUserIdUsesCachedUserWithoutDecodingToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn(42);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('decode');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );
        $guard->setUser($user);

        $this->assertSame(42, $guard->getUserId());
    }

    public function testSetUserOverridesCachedUser(): void
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

    public function testForgetUserClearsCache(): void
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

    public function testSwitchingTokensResolvesDifferentUsersInSameCoroutine(): void
    {
        $firstUser = m::mock(Authenticatable::class);
        $secondUser = m::mock(Authenticatable::class);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($firstUser);
        $provider->shouldReceive('retrieveById')->with(2)->once()->andReturn($secondUser);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('first-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('decode')->with('second-token')->once()->andReturn(['sub' => 2]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: null,
        );

        $this->assertSame($firstUser, $guard->setToken('first-token')->user());
        $this->assertSame($secondUser, $guard->setToken('second-token')->user());
        $this->assertSame($firstUser, $guard->setToken('first-token')->user());
    }

    public function testOnceUsingIdReturnsUserWhenUserExists(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertSame($user, $guard->onceUsingId(1));
    }

    public function testOnceDoesNotMintTokenAndSetsUser(): void
    {
        $user = m::mock(Authenticatable::class);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnTrue();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->once(['email' => 'foo@bar.com', 'password' => 'secret']));
        $this->assertSame($user, $guard->user());
    }

    public function testOnceUsingIdReturnsFalseWhenUserNotFound(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(999)->andReturn(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->onceUsingId(999));
    }

    public function testTokenByIdReturnsTokenWithoutSettingCurrentUserOrToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('token-by-id');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: null,
        );
        RequestContext::forget();

        $this->assertSame('token-by-id', $guard->tokenById(1));
        $this->assertNull($guard->getToken());
        $this->assertFalse($guard->hasUser());
    }

    public function testInvalidateReturnsGuardInstance(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('invalidate')->with('valid-token', true)->once()->andReturnTrue();

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($guard, $guard->invalidate(true));
    }

    public function testInvalidateThrowsWhenNoTokenIsAvailable(): void
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('Token could not be parsed from the request.');

        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $guard->invalidate();
    }

    public function testUserOrFailThrowsWhenUserIsNotDefined(): void
    {
        $this->expectException(UserNotDefinedException::class);

        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $guard->userOrFail();
    }

    public function testUserOrFailReturnsResolvedUser(): void
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

        $this->assertSame($user, $guard->userOrFail());
    }

    public function testUserPropagatesSecretMisconfiguration(): void
    {
        $this->expectException(SecretMissingException::class);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andThrow(new SecretMissingException);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->user();
    }

    public function testDecodedPayloadIsCachedBetweenUserAndGetPayload(): void
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
        ?int $ttl = 120,
    ): JwtGuard {
        if ($request !== null) {
            RequestContext::set($request);
        }

        return new JwtGuard(
            'jwt',
            $provider ?? m::mock(UserProvider::class),
            $jwtManager ?? m::mock(ManagerContract::class),
            new ClaimFactory(new Repository([
                'jwt' => [
                    'issuer' => null,
                    'lock_subject' => true,
                ],
            ])),
            new Parser([new AuthHeaders, new InputSource]),
            $this->app,
            $ttl,
        );
    }

    /**
     * Create a request mock with a Bearer token.
     */
    protected function createRequestWithBearer(?string $token): Request
    {
        return Request::create(
            '/',
            'GET',
            server: $token !== null ? ['HTTP_AUTHORIZATION' => "Bearer {$token}"] : []
        );
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
