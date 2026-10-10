<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Carbon\CarbonInterval;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\AuthServiceProvider;
use Hypervel\Config\Repository;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Foundation\Application;
use Hypervel\Http\Request;
use Hypervel\Jwt\ClaimFactory;
use Hypervel\Jwt\Contracts\ManagerContract;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\SecretMissingException;
use Hypervel\Jwt\Exceptions\TokenBlacklistedException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Exceptions\UserNotDefinedException;
use Hypervel\Jwt\Http\Parser\AuthHeaders;
use Hypervel\Jwt\Http\Parser\InputSource;
use Hypervel\Jwt\Http\Parser\Parser;
use Hypervel\Jwt\JwtGuard;
use Hypervel\Jwt\JwtServiceProvider;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

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
        $this->assertTrue($guard->check());
        $this->assertSame(42, $guard->id());
    }

    public function testUserReturnsNullWhenNoToken(): void
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
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

    public function testMissingTokenUserIsCachedAsUnauthenticated(): void
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
        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());
        $this->assertSame(42, $guard->getUserId());
    }

    #[DataProvider('unusableTokenExceptionProvider')]
    public function testUnusableTokenIsCachedAsUnauthenticated(JwtException $exception): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldNotReceive('retrieveById');

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('unusable-token')->once()->andThrow($exception);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('unusable-token'),
        );

        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());

        $this->expectException(UserNotDefinedException::class);

        $guard->userOrFail();
    }

    /**
     * Provide the token exceptions that leave the guard unauthenticated.
     *
     * @return array<string, array{JwtException}>
     */
    public static function unusableTokenExceptionProvider(): array
    {
        return [
            'invalid' => [new TokenInvalidException],
            'expired' => [new TokenExpiredException],
            'blacklisted' => [new TokenBlacklistedException],
        ];
    }

    public function testAttemptReturnsTokenOnValidCredentials(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->with($credentials)->andReturn($user);
        $provider->shouldReceive('validateCredentials')->with($user, $credentials)->andReturnTrue();
        $provider->shouldReceive('rehashPasswordIfRequired')->with($user, $credentials)->once();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertSame('new-token', $guard->attempt($credentials));
        $this->assertSame($user, $guard->getLastAttempted());
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
        $provider->shouldNotReceive('rehashPasswordIfRequired');

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
        $provider->shouldNotReceive('rehashPasswordIfRequired');

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

    public function testLoginReplacesAnExplicitUserWithTheLoggedInUser(): void
    {
        $explicitUser = m::mock(Authenticatable::class);
        $loginUser = m::mock(Authenticatable::class);
        $loginUser->shouldReceive('getAuthIdentifier')->andReturn(1);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $guard->setUser($explicitUser);

        $this->assertSame('new-token', $guard->login($loginUser));
        $this->assertSame($loginUser, $guard->user());
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

    public function testRefreshPreservesExplicitUserAfterSuccess(): void
    {
        $explicitUser = m::mock(Authenticatable::class);
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')->with('old-token', false, false, [], 120)->once()->andReturn('new-token');
        $jwtManager->shouldNotReceive('decode');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );
        $guard->setUser($explicitUser);

        $this->assertSame('new-token', $guard->refresh());
        $this->assertSame($explicitUser, $guard->setToken('unrelated-token')->user());
    }

    public function testRefreshPreservesExplicitUserAfterFailure(): void
    {
        $explicitUser = m::mock(Authenticatable::class);
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('refresh')
            ->with('old-token', false, false, [], 120)
            ->once()
            ->andThrow(new JwtException('refresh failed'));
        $jwtManager->shouldNotReceive('decode');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );
        $guard->setUser($explicitUser);

        try {
            $guard->refresh();

            $this->fail('Expected refresh to fail.');
        } catch (JwtException $exception) {
            $this->assertSame('refresh failed', $exception->getMessage());
        }

        $this->assertSame($explicitUser, $guard->user());
    }

    public function testRefreshReturnsNullWhenNoToken(): void
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->refresh());
    }

    public function testLogoutInvalidatesTheRequestTokenAndStopsUsingIt(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->twice()->andReturn($user);

        // The grace period keeps the revoked token decodable during this request.
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->twice()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')->with('valid-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($user, $guard->user());

        $guard->logout();

        $this->assertNull($guard->getToken());
        $this->assertFalse($guard->hasUser());
        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());

        $this->assertSame($user, $guard->setToken('valid-token')->user());
    }

    public function testLogoutWithBlacklistDisabledStopsUsingTheRequestTokenWithoutInvalidating(): void
    {
        $user = m::mock(Authenticatable::class);
        $nextUser = m::mock(Authenticatable::class);
        $nextUser->shouldReceive('getAuthIdentifier')->andReturn(2);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnFalse();
        $jwtManager->shouldNotReceive('invalidate');
        $jwtManager->shouldReceive('encode')->once()->andReturn('next-token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($user, $guard->user());

        $guard->logout();

        $this->assertNull($guard->getToken());
        $this->assertSame([], $guard->getPayload());
        $this->assertNull($guard->user());
        $this->assertFalse($guard->check());
        $this->assertNull($guard->id());

        $this->assertSame('next-token', $guard->login($nextUser));
        $this->assertSame('next-token', $guard->getToken());
        $this->assertSame($nextUser, $guard->user());
    }

    public function testLogoutClearsDecodedPayloadCache(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $this->stubDecodeThenBlacklisted($jwtManager, 'valid-token', ['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')->with('valid-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame(['sub' => 1], $guard->getPayload());

        $guard->logout();

        $this->assertSame([], $guard->getPayload());

        $this->expectException(TokenBlacklistedException::class);

        $guard->setToken('valid-token')->getPayload();
    }

    public function testLogoutFailureRetainsTheTokenAndUser(): void
    {
        $user = m::mock(Authenticatable::class);
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')
            ->with('valid-token', false)
            ->once()
            ->andThrow(new JwtException('blacklist write failed'));

        $guard = $this->createGuard(jwtManager: $jwtManager, request: null)
            ->setToken('valid-token')
            ->setUser($user);

        $this->assertSame(['sub' => 1], $guard->getPayload());

        try {
            $guard->logout();

            $this->fail('Expected logout to fail when the blacklist write fails.');
        } catch (JwtException $exception) {
            $this->assertSame('blacklist write failed', $exception->getMessage());
        }

        $this->assertSame('valid-token', $guard->getToken());
        $this->assertSame($user, $guard->user());
        $this->assertSame(['sub' => 1], $guard->getPayload());
    }

    public function testLogoutPassesForceForeverFlag(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')->with('valid-token', true)->once()->andReturnTrue();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->logout(true);
    }

    public function testLogoutWithoutTokenClearsLocalUserState(): void
    {
        $user = m::mock(Authenticatable::class);
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('invalidate');

        $guard = $this->createGuard(jwtManager: $jwtManager, request: null);
        RequestContext::forget();
        $guard->setUser($user);

        $guard->logout();

        $this->assertNull($guard->getToken());
        $this->assertFalse($guard->hasUser());
    }

    public function testGetUserReturnsTheResolvedUser(): void
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

        $this->assertSame($user, $guard->getUser());
        $this->assertTrue($guard->hasUser());
    }

    public function testGetUserDoesNotResolveTheTokenUser(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('decode');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertNull($guard->getUser());
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

    public function testGetUserIdReadsTheTokenSubjectWithoutLoadingTheUser(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldNotReceive('retrieveById');

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 42]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame(42, $guard->getUserId());
        $this->assertFalse($guard->hasUser());
    }

    public function testGetUserIdReturnsNullWithoutToken(): void
    {
        $guard = $this->createGuard(request: null);
        RequestContext::forget();

        $this->assertNull($guard->getUserId());
    }

    public function testExplicitUserOverridesAnUnrelatedTokenUntilForgotten(): void
    {
        $explicitUser = m::mock(Authenticatable::class);
        $explicitUser->shouldReceive('getAuthIdentifier')->once()->andReturn(10);
        $tokenUser = m::mock(Authenticatable::class);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(20)->once()->andReturn($tokenUser);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('bearer-token')->once()->andReturn(['sub' => 20]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('bearer-token'),
        );
        $guard->setUser($explicitUser);

        $this->assertSame($explicitUser, $guard->user());
        $this->assertSame(10, $guard->getUserId());
        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertSame($tokenUser, $guard->user());
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

    public function testAuthContextKeysIncludeOnlyDurableExplicitState(): void
    {
        $guard = $this->createGuard(
            request: $this->createRequestWithBearer('request-token'),
        );

        $this->assertSame([
            '__auth.guards.jwt.user.explicit',
        ], $guard->getAuthContextKeys());
    }

    public function testLogoutClearsDefaultUserCacheWhenTokenOverrideIsActive(): void
    {
        $user = m::mock(Authenticatable::class);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('hasBlacklistEnabled')->once()->andReturnTrue();
        $jwtManager->shouldReceive('invalidate')->with('active-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: null,
        );
        RequestContext::forget();

        $guard->setUser($user);
        $this->assertTrue($guard->hasUser());

        $guard->setToken('active-token');
        $guard->logout();

        $this->assertNull($guard->getToken());
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

    public function testOnceUsingIdAndByIdReturnUserWhenUserExists(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->twice()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertSame($user, $guard->onceUsingId(1));
        $this->assertSame($user, $guard->byId(1));
        $this->assertSame($user, $guard->user());
    }

    public function testOnceDoesNotMintTokenAndSetsUser(): void
    {
        $user = m::mock(Authenticatable::class);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnTrue();
        $provider->shouldReceive('rehashPasswordIfRequired')->with($user, $credentials)->once();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->once($credentials));
        $this->assertSame($user, $guard->user());
    }

    public function testOnceReturnsFalseForInvalidCredentials(): void
    {
        $user = m::mock(Authenticatable::class);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnFalse();
        $provider->shouldNotReceive('rehashPasswordIfRequired');

        $guard = $this->createGuard(provider: $provider, request: $this->createRequestWithBearer(null));

        $this->assertFalse($guard->once(['email' => 'foo@bar.com', 'password' => 'wrong']));
        $this->assertFalse($guard->hasUser());
    }

    public function testDisabledRehashOnLoginNeverRehashesPasswords(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->twice()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->twice()->andReturnTrue();
        $provider->shouldNotReceive('rehashPasswordIfRequired');

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
            rehashOnLogin: false,
        );

        $this->assertSame('new-token', $guard->attempt(['email' => 'foo@bar.com', 'password' => 'secret']));
        $this->assertTrue($guard->once(['email' => 'foo@bar.com', 'password' => 'secret']));
    }

    public function testFailedAttemptWaitsForTheTimebox(): void
    {
        Sleep::fake();

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturnNull();

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
            timeboxDuration: 200000,
        );

        $this->assertFalse($guard->attempt(['email' => 'missing@bar.com', 'password' => 'secret']));

        Sleep::assertSlept(
            static fn (CarbonInterval $duration): bool => $duration->totalMicroseconds > 0
                && $duration->totalMicroseconds <= 200000
        );
    }

    public function testSuccessfulAttemptReturnsWithoutWaitingForTheTimebox(): void
    {
        Sleep::fake();

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnTrue();
        $provider->shouldReceive('rehashPasswordIfRequired')->once();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
            timeboxDuration: 200000,
        );

        $this->assertSame('new-token', $guard->attempt(['email' => 'foo@bar.com', 'password' => 'secret']));

        Sleep::assertNeverSlept();
    }

    public function testOnceUsingIdAndByIdReturnFalseWhenUserNotFound(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(999)->twice()->andReturn(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->onceUsingId(999));
        $this->assertFalse($guard->byId(999));
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

    public function testTokenByIdReturnsNullWhenUserIsNotFound(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturnNull();

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldNotReceive('encode');

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null);

        $this->assertNull($guard->tokenById(1));
    }

    public function testFromUserReturnsTokenWithoutSettingCurrentUserOrToken(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $capturedPayload = null;
        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturnUsing(function (array $payload) use (&$capturedPayload): string {
            $capturedPayload = $payload;

            return 'token-for-user';
        });

        $guard = $this->createGuard(jwtManager: $jwtManager, request: null);
        RequestContext::forget();

        $this->assertSame('token-for-user', $guard->claims(['role' => 'admin'])->fromUser($user));
        $this->assertSame(1, $capturedPayload['sub']);
        $this->assertSame('admin', $capturedPayload['role']);
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

    public function testInvalidateClearsTheExactTokenUserAndRetainsTokenIdentity(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $this->stubDecodeThenBlacklisted($jwtManager, 'valid-token', ['sub' => 1]);
        $jwtManager->shouldReceive('invalidate')->with('valid-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null)
            ->setToken('valid-token');

        $this->assertSame($user, $guard->user());
        $this->assertSame($guard, $guard->invalidate());
        $this->assertSame('valid-token', $guard->getToken());
        $this->assertNull($guard->user());
    }

    public function testInvalidateClearsTheExactTokenPayload(): void
    {
        $jwtManager = m::mock(ManagerContract::class);
        $this->stubDecodeThenBlacklisted($jwtManager, 'valid-token', ['sub' => 1]);
        $jwtManager->shouldReceive('invalidate')->with('valid-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(jwtManager: $jwtManager, request: null)
            ->setToken('valid-token');

        $this->assertSame(['sub' => 1], $guard->getPayload());
        $guard->invalidate();
        $this->assertSame('valid-token', $guard->getToken());

        $this->expectException(TokenBlacklistedException::class);

        $guard->getPayload();
    }

    public function testInvalidateLeavesSiblingTokenStateUntouched(): void
    {
        $firstUser = m::mock(Authenticatable::class);
        $secondUser = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($firstUser);
        $provider->shouldReceive('retrieveById')->with(2)->once()->andReturn($secondUser);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('first-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('decode')->with('second-token')->once()->andReturn(['sub' => 2]);
        $jwtManager->shouldReceive('invalidate')->with('first-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null);

        $this->assertSame($firstUser, $guard->setToken('first-token')->user());
        $this->assertSame($secondUser, $guard->setToken('second-token')->user());

        $guard->setToken('first-token')->invalidate();

        $this->assertSame($secondUser, $guard->setToken('second-token')->user());
        $this->assertSame(['sub' => 2], $guard->getPayload());
    }

    public function testInvalidateFailureLeavesExactTokenStateUntouched(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->once()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->once()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('invalidate')
            ->with('valid-token', false)
            ->once()
            ->andThrow(new JwtException('blacklist write failed'));

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null)
            ->setToken('valid-token');

        $this->assertSame($user, $guard->user());

        try {
            $guard->invalidate();
            $this->fail('Expected token invalidation to fail.');
        } catch (JwtException $exception) {
            $this->assertSame('blacklist write failed', $exception->getMessage());
        }

        $this->assertSame('valid-token', $guard->getToken());
        $this->assertSame($user, $guard->user());
        $this->assertSame(['sub' => 1], $guard->getPayload());
    }

    public function testInvalidateReevaluatesTokenWhenManagerGracePeriodAllowsIt(): void
    {
        $user = m::mock(Authenticatable::class);
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(1)->twice()->andReturn($user);

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('decode')->with('valid-token')->twice()->andReturn(['sub' => 1]);
        $jwtManager->shouldReceive('invalidate')->with('valid-token', false)->once()->andReturnTrue();

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null)
            ->setToken('valid-token');

        $this->assertSame($user, $guard->user());
        $guard->invalidate();
        $this->assertSame($user, $guard->user());
    }

    public function testInvalidateThrowsWhenNoTokenIsAvailable(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Token could not be parsed from the request.');

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

    public function testGuardIsMacroable(): void
    {
        JwtGuard::macro('foo', static fn (): string => 'bar');

        $this->assertSame('bar', $this->createGuard()->foo());
    }

    public function testServiceProviderRegistersJwtGuardWhenAuthManagerResolvesAfterBoot(): void
    {
        $provider = m::mock(UserProvider::class);
        $container = $this->createAuthTestContainer();

        $jwtServiceProvider = new JwtServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', m::mock(ManagerContract::class));
        $jwtServiceProvider->boot();

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn ($app, $config) => $provider);

        $this->assertInstanceOf(JwtGuard::class, $authManager->guard('jwt'));
    }

    public function testServiceProviderRegistersJwtGuardWhenAuthManagerIsAlreadyResolved(): void
    {
        $provider = m::mock(UserProvider::class);
        $container = $this->createAuthTestContainer();

        $jwtServiceProvider = new JwtServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', m::mock(ManagerContract::class));

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn ($app, $config) => $provider);

        $jwtServiceProvider->boot();

        $this->assertInstanceOf(JwtGuard::class, $authManager->guard('jwt'));
    }

    public function testRegisteredGuardUsesTheTimeboxAndRehashSettings(): void
    {
        Sleep::fake();

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->with(['email' => 'missing@bar.com'])->once()->andReturnNull();
        $provider->shouldReceive('retrieveByCredentials')->with(['email' => 'foo@bar.com'])->once()->andReturn($user);
        $provider->shouldReceive('validateCredentials')->once()->andReturnTrue();
        $provider->shouldNotReceive('rehashPasswordIfRequired');

        $jwtManager = m::mock(ManagerContract::class);
        $jwtManager->shouldReceive('encode')->once()->andReturn('new-token');

        $container = $this->createAuthTestContainer(rehashOnLogin: false, timeboxDuration: 300000);
        $jwtServiceProvider = new JwtServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', $jwtManager);
        $jwtServiceProvider->boot();

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', static fn (): UserProvider => $provider);
        $guard = $authManager->guard('jwt');

        // Succeeding first shows a later failure on the same guard still waits.
        $this->assertSame('new-token', $guard->attempt(['email' => 'foo@bar.com']));
        Sleep::assertNeverSlept();

        $this->assertFalse($guard->attempt(['email' => 'missing@bar.com']));

        // A wait above the framework's 200000 microsecond default shows the configured duration is used.
        Sleep::assertSlept(
            static fn (CarbonInterval $duration): bool => $duration->totalMicroseconds > 200000
                && $duration->totalMicroseconds <= 300000
        );
    }

    /**
     * Stub decoding to succeed once and then report a blacklisted token.
     *
     * @param array<string, mixed> $payload
     */
    protected function stubDecodeThenBlacklisted(
        ManagerContract&MockInterface $jwtManager,
        string $token,
        array $payload,
    ): void {
        $decodeCalls = 0;

        $jwtManager->shouldReceive('decode')
            ->with($token)
            ->twice()
            ->andReturnUsing(function () use (&$decodeCalls, $payload): array {
                if (++$decodeCalls === 1) {
                    return $payload;
                }

                throw new TokenBlacklistedException('The token has been blacklisted');
            });
    }

    /**
     * Create a JwtGuard instance for testing.
     */
    protected function createGuard(
        ?UserProvider $provider = null,
        ?ManagerContract $jwtManager = null,
        ?Request $request = null,
        ?int $ttl = 120,
        bool $rehashOnLogin = true,
        int $timeboxDuration = 0,
    ): JwtGuard {
        if ($request !== null) {
            RequestContext::set($request);
        }

        return new JwtGuard(
            name: 'jwt',
            provider: $provider ?? m::mock(UserProvider::class),
            jwtManager: $jwtManager ?? m::mock(ManagerContract::class),
            claimFactory: new ClaimFactory(new Repository([
                'jwt' => [
                    'issuer' => null,
                    'lock_subject' => true,
                ],
            ])),
            parser: new Parser([new AuthHeaders, new InputSource]),
            app: $this->app,
            rehashOnLogin: $rehashOnLogin,
            timeboxDuration: $timeboxDuration,
            ttl: $ttl,
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

    /**
     * Create an application container for guard registration tests.
     */
    protected function createAuthTestContainer(bool $rehashOnLogin = true, int $timeboxDuration = 200000): Application
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
                'timebox_duration' => $timeboxDuration,
            ],
            'hashing' => [
                'rehash_on_login' => $rehashOnLogin,
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
