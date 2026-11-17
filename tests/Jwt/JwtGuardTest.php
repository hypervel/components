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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Coroutine\parallel;

class JwtGuardTest extends TestCase
{
    use VerifiesDoubles;

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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(42);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(42)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 42]);

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(42)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 42]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->user()); // Should not call decode again
    }

    public function testNewGuardWithTheSameNameStartsWithoutThePreviousGuardsState(): void
    {
        $previousUser = Double::for(Authenticatable::class)->strict();
        $previousProvider = Double::for(UserProvider::class)->strict();
        $previousProvider->expects('retrieveById')->with(42)->returns($previousUser);
        $previousManager = Double::for(ManagerContract::class)->strict();
        $previousManager->expects('decode')->with('request-token')->returns(['sub' => 42]);
        $previousGuard = $this->createGuard(
            provider: $previousProvider,
            jwtManager: $previousManager,
            request: $this->createRequestWithBearer('request-token'),
        );
        $this->assertSame($previousUser, $previousGuard->user());
        $previousGuard->setUser(Double::for(Authenticatable::class)->strict());
        $previousGuard->setToken('previous-token');
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(7)->returns(null);
        $manager = Double::for(ManagerContract::class)->strict();
        $manager->expects('decode')->with('request-token')->returns(['sub' => 7]);

        $guard = $this->createGuard(provider: $provider, jwtManager: $manager);

        $this->assertFalse($guard->hasUser());
        $this->assertNull($guard->user());
        $this->assertSame(['sub' => 7], $guard->payload());
    }

    public function testCopiedRequestRechecksBlacklistAfterWarmingUserAndPayload(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(42)->returns($user);
        $manager = Double::for(ManagerContract::class)->strict();
        $this->stubDecodeThenBlacklisted($manager, 'token', ['sub' => 42]);
        $request = $this->createRequestWithBearer('token');
        $guard = $this->createGuard(provider: $provider, jwtManager: $manager, request: $request);

        $this->assertSame($user, $guard->user());
        $this->assertSame(['sub' => 42], $guard->payload());

        parallel([function () use ($guard, $request): void {
            RequestContext::set(clone $request);
            $this->assertFalse($guard->hasUser());
            $this->assertNull($guard->user());
        }], copyContext: true);

        $this->assertSame($user, $guard->user());
    }

    public function testMissingTokenUserIsCachedAsUnauthenticated(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(42)->returns(null);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 42]);

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
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('unusable-token')->throws($exception);

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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];

        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveByCredentials')->with($credentials)->returns($user);
        $provider->allows('validateCredentials')->with($user, $credentials)->returns(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, $credentials);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('new-token');

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
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveByCredentials')->returns(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->attempt(['email' => 'foo@bar.com', 'password' => 'wrong']));
    }

    public function testValidateDoesNotLoginUser(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveByCredentials')->returns($user);
        $provider->allows('validateCredentials')->returns(true);
        $provider->expects('rehashPasswordIfRequired')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->never();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->validate(['email' => 'foo@bar.com', 'password' => 'secret']));
    }

    public function testAttemptWithoutLoginReturnsTrueAndDoesNotMintToken(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->returns(true);
        $provider->expects('rehashPasswordIfRequired')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->never();

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertTrue($guard->attempt(['email' => 'foo@bar.com', 'password' => 'secret'], false));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testLoginReturnsToken(bool $withRequest): void
    {
        RequestContext::forget();
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('jwt-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $withRequest ? $this->createRequestWithBearer(null) : null,
        );

        $token = $guard->login($user);

        $this->assertSame('jwt-token', $token);
        $this->assertSame('jwt-token', $guard->getToken());
        $this->assertSame($user, $guard->user());
    }

    public function testLoginOverridesExistingRequestToken(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('new-token');
        $jwtManager->expects('decode')->never();

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
        $explicitUser = Double::for(Authenticatable::class)->strict();
        $loginUser = Double::for(Authenticatable::class)->strict();
        $loginUser->allows('getAuthIdentifier')->returns(1);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('new-token');

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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(42);

        $capturedPayload = null;
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->resolves(function ($payload) use (&$capturedPayload) {
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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(42);

        $capturedPayload = null;
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->resolves(function ($payload) use (&$capturedPayload) {
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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $capturedPayload = null;
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->resolves(function ($payload) use (&$capturedPayload) {
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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $capturedPayloads = [];
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->times(2)->resolves(function ($payload) use (&$capturedPayloads) {
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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $capturedPayloads = [];
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->times(2)->resolves(function ($payload) use (&$capturedPayloads) {
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
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $capturedPayloads = [];
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->times(2)->resolves(function ($payload) use (&$capturedPayloads) {
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
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 1, 'iat' => 1000]);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $payload = $guard->getPayload();

        $this->assertSame(['sub' => 1, 'iat' => 1000], $payload);
    }

    public function testPayloadAliasesGetPayload(): void
    {
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 1, 'iat' => 1000]);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame(['sub' => 1, 'iat' => 1000], $guard->payload());
    }

    public function testGetPayloadThrowsWhenPresentTokenIsInvalid(): void
    {
        $this->expectException(TokenInvalidException::class);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('invalid-token')->throws(new TokenInvalidException);

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
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('refresh')->with('old-token', false, false, [], 120)->returns('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->refresh());
    }

    public function testRefreshUsesPerCallTtlOverrideAndClearsIt(): void
    {
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('refresh')->with('old-token', false, false, [], 5)->returns('new-token');
        $jwtManager->expects('refresh')->with('new-token', false, false, [], 120)->returns('newer-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->setTTL(5)->refresh());
        $this->assertSame('newer-token', $guard->refresh());
    }

    public function testRefreshUsesNullTtlOverride(): void
    {
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('refresh')->with('old-token', false, false, [], null)->returns('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
        );

        $this->assertSame('new-token', $guard->setTTL(null)->refresh());
    }

    public function testRefreshUsesPerGuardTtl(): void
    {
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('refresh')->with('old-token', false, false, [], 15)->returns('new-token');

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('old-token'),
            ttl: 15,
        );

        $this->assertSame('new-token', $guard->refresh());
    }

    public function testRefreshKeepsCachedUserUnderNewToken(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('old-token')->returns(['sub' => 1]);
        $jwtManager->expects('refresh')->with('old-token', false, false, [], 120)->returns('new-token');

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
        $explicitUser = Double::for(Authenticatable::class)->strict();
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('refresh')->with('old-token', false, false, [], 120)->returns('new-token');
        $jwtManager->expects('decode')->never();

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
        $explicitUser = Double::for(Authenticatable::class)->strict();
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('refresh')
            ->with('old-token', false, false, [], 120)
            ->throws(new JwtException('refresh failed'));
        $jwtManager->expects('decode')->never();

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->times(2)->returns($user);

        // The grace period keeps the revoked token decodable during this request.
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->times(2)->returns(['sub' => 1]);
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')->with('valid-token', false)->returns(true);

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
        $user = Double::for(Authenticatable::class)->strict();
        $nextUser = Double::for(Authenticatable::class)->strict();
        $nextUser->allows('getAuthIdentifier')->returns(2);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 1]);
        $jwtManager->expects('hasBlacklistEnabled')->returns(false);
        $jwtManager->expects('invalidate')->never();
        $jwtManager->expects('encode')->returns('next-token');

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $this->stubDecodeThenBlacklisted($jwtManager, 'valid-token', ['sub' => 1]);
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')->with('valid-token', false)->returns(true);

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
        $user = Double::for(Authenticatable::class)->strict();
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 1]);
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')
            ->with('valid-token', false)
            ->throws(new JwtException('blacklist write failed'));

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 1]);
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')->with('valid-token', true)->returns(true);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->logout(true);
    }

    public function testLogoutWithoutTokenClearsLocalUserState(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('invalidate')->never();

        $guard = $this->createGuard(jwtManager: $jwtManager, request: null);
        RequestContext::forget();
        $guard->setUser($user);

        $guard->logout();

        $this->assertNull($guard->getToken());
        $this->assertFalse($guard->hasUser());
    }

    public function testGetUserReturnsTheResolvedUser(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->allows('decode')->with('valid-token')->returns(['sub' => 1]);

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
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->never();

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertNull($guard->getUser());
        $this->assertFalse($guard->hasUser());
    }

    public function testGetUserIdUsesCachedUserWithoutDecodingToken(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $user->expects('getAuthIdentifier')->returns(42);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->never();

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );
        $guard->setUser($user);

        $this->assertSame(42, $guard->getUserId());
    }

    public function testGetUserIdReadsTheTokenSubjectWithoutLoadingTheUser(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 42]);

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
        $explicitUser = Double::for(Authenticatable::class)->strict();
        $explicitUser->expects('getAuthIdentifier')->returns(10);
        $tokenUser = Double::for(Authenticatable::class)->strict();

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(20)->returns($tokenUser);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('bearer-token')->returns(['sub' => 20]);

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
        $user1 = Double::for(Authenticatable::class)->strict();
        $user2 = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveById')->with(1)->returns($user1);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->allows('decode')->with('valid-token')->returns(['sub' => 1]);

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->allows('decode')->with('valid-token')->returns(['sub' => 1]);

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
            (fn (): string => $this->getExplicitUserContextKey())->call($guard),
        ], $guard->getAuthContextKeys());
    }

    public function testLogoutClearsDefaultUserCacheWhenTokenOverrideIsActive(): void
    {
        $user = Double::for(Authenticatable::class)->strict();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('hasBlacklistEnabled')->returns(true);
        $jwtManager->expects('invalidate')->with('active-token', false)->returns(true);

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
        $firstUser = Double::for(Authenticatable::class)->strict();
        $secondUser = Double::for(Authenticatable::class)->strict();

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($firstUser);
        $provider->expects('retrieveById')->with(2)->returns($secondUser);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('first-token')->returns(['sub' => 1]);
        $jwtManager->expects('decode')->with('second-token')->returns(['sub' => 2]);

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->times(2)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->never();

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
        $user = Double::for(Authenticatable::class)->strict();
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->returns(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, $credentials);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->never();

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
        $user = Double::for(Authenticatable::class)->strict();

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->returns(false);
        $provider->expects('rehashPasswordIfRequired')->never();

        $guard = $this->createGuard(provider: $provider, request: $this->createRequestWithBearer(null));

        $this->assertFalse($guard->once(['email' => 'foo@bar.com', 'password' => 'wrong']));
        $this->assertFalse($guard->hasUser());
    }

    public function testDisabledRehashOnLoginNeverRehashesPasswords(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->times(2)->returns($user);
        $provider->expects('validateCredentials')->times(2)->returns(true);
        $provider->expects('rehashPasswordIfRequired')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('new-token');

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

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns(null);

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

        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->returns(true);
        $provider->expects('rehashPasswordIfRequired');

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('new-token');

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
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(999)->times(2)->returns(null);

        $guard = $this->createGuard(
            provider: $provider,
            request: $this->createRequestWithBearer(null),
        );

        $this->assertFalse($guard->onceUsingId(999));
        $this->assertFalse($guard->byId(999));
    }

    public function testTokenByIdReturnsTokenWithoutSettingCurrentUserOrToken(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('token-by-id');

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
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns(null);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->never();

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null);

        $this->assertNull($guard->tokenById(1));
    }

    public function testFromUserReturnsTokenWithoutSettingCurrentUserOrToken(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $capturedPayload = null;
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->resolves(function (array $payload) use (&$capturedPayload): string {
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
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('invalidate')->with('valid-token', true)->returns(true);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $this->assertSame($guard, $guard->invalidate(true));
    }

    public function testInvalidateClearsTheExactTokenUserAndRetainsTokenIdentity(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $this->stubDecodeThenBlacklisted($jwtManager, 'valid-token', ['sub' => 1]);
        $jwtManager->expects('invalidate')->with('valid-token', false)->returns(true);

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null)
            ->setToken('valid-token');

        $this->assertSame($user, $guard->user());
        $this->assertSame($guard, $guard->invalidate());
        $this->assertSame('valid-token', $guard->getToken());
        $this->assertNull($guard->user());
    }

    public function testInvalidateClearsTheExactTokenPayload(): void
    {
        $jwtManager = Double::for(ManagerContract::class)->strict();
        $this->stubDecodeThenBlacklisted($jwtManager, 'valid-token', ['sub' => 1]);
        $jwtManager->expects('invalidate')->with('valid-token', false)->returns(true);

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
        $firstUser = Double::for(Authenticatable::class)->strict();
        $secondUser = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($firstUser);
        $provider->expects('retrieveById')->with(2)->returns($secondUser);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('first-token')->returns(['sub' => 1]);
        $jwtManager->expects('decode')->with('second-token')->returns(['sub' => 2]);
        $jwtManager->expects('invalidate')->with('first-token', false)->returns(true);

        $guard = $this->createGuard(provider: $provider, jwtManager: $jwtManager, request: null);

        $this->assertSame($firstUser, $guard->setToken('first-token')->user());
        $this->assertSame($secondUser, $guard->setToken('second-token')->user());

        $guard->setToken('first-token')->invalidate();

        $this->assertSame($secondUser, $guard->setToken('second-token')->user());
        $this->assertSame(['sub' => 2], $guard->getPayload());
    }

    public function testInvalidateFailureLeavesExactTokenStateUntouched(): void
    {
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->returns(['sub' => 1]);
        $jwtManager->expects('invalidate')
            ->with('valid-token', false)
            ->throws(new JwtException('blacklist write failed'));

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveById')->with(1)->times(2)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->times(2)->returns(['sub' => 1]);
        $jwtManager->expects('invalidate')->with('valid-token', false)->returns(true);

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
        $user = Double::for(Authenticatable::class)->strict();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveById')->with(1)->returns($user);

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->allows('decode')->with('valid-token')->returns(['sub' => 1]);

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

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('decode')->with('valid-token')->throws(new SecretMissingException);

        $guard = $this->createGuard(
            jwtManager: $jwtManager,
            request: $this->createRequestWithBearer('valid-token'),
        );

        $guard->user();
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testDecodedPayloadIsCachedBetweenUserAndGetPayload(bool $withRequest): void
    {
        RequestContext::forget();
        $provider = Double::for(UserProvider::class)->strict();
        $provider->allows('retrieveById')->with(1)->returns(
            Double::for(Authenticatable::class)->strict()
        );

        $jwtManager = Double::for(ManagerContract::class)->strict();
        // decode() should be called exactly once — the second call uses the cache
        $jwtManager->expects('decode')->with('valid-token')->returns([
            'sub' => 1,
            'iat' => 1000,
            'exp' => 9999999999,
        ]);

        $guard = $this->createGuard(
            provider: $provider,
            jwtManager: $jwtManager,
            request: $withRequest ? $this->createRequestWithBearer('valid-token') : null,
        );

        if (! $withRequest) {
            $guard->setToken('valid-token');
        }

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
        $provider = Double::for(UserProvider::class)->strict();
        $container = $this->createAuthTestContainer();

        $jwtServiceProvider = new JwtServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', Double::for(ManagerContract::class)->strict());
        $jwtServiceProvider->boot();

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn ($app, $config) => $provider);

        $this->assertInstanceOf(JwtGuard::class, $authManager->guard('jwt'));
    }

    public function testServiceProviderRegistersJwtGuardWhenAuthManagerIsAlreadyResolved(): void
    {
        $provider = Double::for(UserProvider::class)->strict();
        $container = $this->createAuthTestContainer();

        $jwtServiceProvider = new JwtServiceProvider($container);
        $jwtServiceProvider->register();
        $container->instance('jwt', Double::for(ManagerContract::class)->strict());

        /** @var AuthManager $authManager */
        $authManager = $container->make(AuthManager::class);
        $authManager->provider('jwt-test-provider', fn ($app, $config) => $provider);

        $jwtServiceProvider->boot();

        $this->assertInstanceOf(JwtGuard::class, $authManager->guard('jwt'));
    }

    public function testRegisteredGuardUsesTheTimeboxAndRehashSettings(): void
    {
        Sleep::fake();

        $user = Double::for(Authenticatable::class)->strict();
        $user->allows('getAuthIdentifier')->returns(1);

        $provider = Double::for(UserProvider::class)->strict();
        $provider->expects('retrieveByCredentials')->with(['email' => 'missing@bar.com'])->returns(null);
        $provider->expects('retrieveByCredentials')->with(['email' => 'foo@bar.com'])->returns($user);
        $provider->expects('validateCredentials')->returns(true);
        $provider->expects('rehashPasswordIfRequired')->never();

        $jwtManager = Double::for(ManagerContract::class)->strict();
        $jwtManager->expects('encode')->returns('new-token');

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
        ManagerContract&DoubleInterface $jwtManager,
        string $token,
        array $payload,
    ): void {
        $decodeCalls = 0;

        $jwtManager->expects('decode')
            ->with($token)
            ->times(2)
            ->resolves(function () use (&$decodeCalls, $payload): array {
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
            provider: $provider ?? Double::for(UserProvider::class)->strict(),
            jwtManager: $jwtManager ?? Double::for(ManagerContract::class)->strict(),
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
