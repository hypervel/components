<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\TokenGuard;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class AuthTokenGuardTest extends TestCase
{
    /**
     * Create a TokenGuard with a request seeded in coroutine context.
     */
    protected function createGuard(
        UserProvider $provider,
        Request $request,
        string $inputKey = 'api_token',
        string $storageKey = 'api_token',
        bool $hash = false,
    ): TokenGuard {
        RequestContext::set($request);

        return new TokenGuard('token', $provider, $this->app, $inputKey, $storageKey, $hash);
    }

    public function testUserCanBeRetrievedByQueryStringVariable()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'foo'])->andReturn($user);
        $request = Request::create('/', 'GET', ['api_token' => 'foo']);

        $guard = $this->createGuard($provider, $request);

        $user = $guard->user();

        $this->assertSame(1, $user->id);
        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
        $this->assertSame(1, $guard->id());
    }

    public function testTokenCanBeHashed()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => hash('sha256', 'foo')])->andReturn($user);
        $request = Request::create('/', 'GET', ['api_token' => 'foo']);

        $guard = $this->createGuard($provider, $request, 'api_token', 'api_token', hash: true);

        $user = $guard->user();

        $this->assertSame(1, $user->id);
        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
        $this->assertSame(1, $guard->id());
    }

    public function testUserCanBeRetrievedByAuthHeaders()
    {
        $provider = m::mock(UserProvider::class);
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->id = 1;
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(1);
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'foo'])->andReturn($mockUser);
        $request = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo', 'PHP_AUTH_PW' => 'foo']);

        $guard = $this->createGuard($provider, $request);

        $user = $guard->user();

        $this->assertSame(1, $user->id);
    }

    public function testUserCanBeRetrievedByBearerToken()
    {
        $provider = m::mock(UserProvider::class);
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->id = 1;
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(1);
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'foo'])->andReturn($mockUser);
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer foo']);

        $guard = $this->createGuard($provider, $request);

        $user = $guard->user();

        $this->assertSame(1, $user->id);
    }

    public function testValidateCanDetermineIfCredentialsAreValid()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'foo'])->andReturn($user);
        $request = Request::create('/', 'GET', ['api_token' => 'foo']);

        $guard = $this->createGuard($provider, $request);

        $this->assertTrue($guard->validate(['api_token' => 'foo']));
    }

    public function testValidateCanDetermineIfCredentialsAreInvalid()
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'foo'])->andReturn(null);
        $request = Request::create('/', 'GET', ['api_token' => 'foo']);

        $guard = $this->createGuard($provider, $request);

        $this->assertFalse($guard->validate(['api_token' => 'foo']));
    }

    public function testValidateHashesTokenWhenConfigured(): void
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $provider->shouldReceive('retrieveByCredentials')
            ->once()
            ->with(['stored_token' => hash('sha256', 'plain-token')])
            ->andReturn($user);

        $guard = $this->createGuard(
            $provider,
            Request::create('/'),
            inputKey: 'provided_token',
            storageKey: 'stored_token',
            hash: true,
        );

        $this->assertTrue($guard->validate(['provided_token' => 'plain-token']));
    }

    public function testValidateRejectsMissingEmptyAndNonStringTokens(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldNotReceive('retrieveByCredentials');

        $guard = $this->createGuard($provider, Request::create('/'));

        $this->assertFalse($guard->validate());
        $this->assertFalse($guard->validate(['api_token' => '']));
        $this->assertFalse($guard->validate(['api_token' => 123]));
        $this->assertFalse($guard->validate(['api_token' => ['token']]));
    }

    public function testValidatePreservesStringZero(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')
            ->once()
            ->with(['api_token' => '0'])
            ->andReturn(new AuthTokenGuardTestUser);

        $guard = $this->createGuard($provider, Request::create('/'));

        $this->assertTrue($guard->validate(['api_token' => '0']));
    }

    public function testValidateIfApiTokenIsEmpty()
    {
        $provider = m::mock(UserProvider::class);
        $request = Request::create('/', 'GET', ['api_token' => '']);

        $guard = $this->createGuard($provider, $request);

        $this->assertFalse($guard->validate(['api_token' => '']));
    }

    public function testItAllowsToPassCustomRequestViaContainerAndUseItForValidation()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'custom'])->andReturn($user);
        $request = Request::create('/', 'GET', ['api_token' => 'foo']);

        $guard = $this->createGuard($provider, $request);

        // Replace the request in coroutine context (Hypervel resolves request from RequestContext via the container's bind closure)
        RequestContext::set(Request::create('/', 'GET', ['api_token' => 'custom']));

        $user = $guard->user();

        $this->assertSame(1, $user->id);
    }

    public function testUserCanBeRetrievedByBearerTokenWithCustomKey()
    {
        $provider = m::mock(UserProvider::class);
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->id = 1;
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(1);
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['custom_token_field' => 'foo'])->andReturn($mockUser);
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer foo']);

        $guard = $this->createGuard($provider, $request, 'custom_token_field', 'custom_token_field');

        $user = $guard->user();

        $this->assertSame(1, $user->id);
    }

    public function testUserCanBeRetrievedByQueryStringVariableWithCustomKey()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['custom_token_field' => 'foo'])->andReturn($user);
        $request = Request::create('/', 'GET', ['custom_token_field' => 'foo']);

        $guard = $this->createGuard($provider, $request, 'custom_token_field', 'custom_token_field');

        $user = $guard->user();

        $this->assertSame(1, $user->id);
        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
        $this->assertSame(1, $guard->id());
    }

    public function testUserCanBeRetrievedByAuthHeadersWithCustomField()
    {
        $provider = m::mock(UserProvider::class);
        $mockUser = m::mock(Authenticatable::class);
        $mockUser->id = 1;
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(1);
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['custom_token_field' => 'foo'])->andReturn($mockUser);
        $request = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo', 'PHP_AUTH_PW' => 'foo']);

        $guard = $this->createGuard($provider, $request, 'custom_token_field', 'custom_token_field');

        $user = $guard->user();

        $this->assertSame(1, $user->id);
    }

    public function testValidateCanDetermineIfCredentialsAreValidWithCustomKey()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['custom_token_field' => 'foo'])->andReturn($user);
        $request = Request::create('/', 'GET', ['custom_token_field' => 'foo']);

        $guard = $this->createGuard($provider, $request, 'custom_token_field', 'custom_token_field');

        $this->assertTrue($guard->validate(['custom_token_field' => 'foo']));
    }

    public function testValidateCanDetermineIfCredentialsAreInvalidWithCustomKey()
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['custom_token_field' => 'foo'])->andReturn(null);
        $request = Request::create('/', 'GET', ['custom_token_field' => 'foo']);

        $guard = $this->createGuard($provider, $request, 'custom_token_field', 'custom_token_field');

        $this->assertFalse($guard->validate(['custom_token_field' => 'foo']));
    }

    public function testValidateIfApiTokenIsEmptyWithCustomKey()
    {
        $provider = m::mock(UserProvider::class);
        $request = Request::create('/', 'GET', ['custom_token_field' => '']);

        $guard = $this->createGuard($provider, $request, 'custom_token_field', 'custom_token_field');

        $this->assertFalse($guard->validate(['custom_token_field' => '']));
    }

    public function testTokenLookupStopsAfterTheFirstNonEmptyString(): void
    {
        $provider = m::mock(UserProvider::class);
        $request = m::mock(Request::class)->makePartial();
        $request->shouldReceive('query')->once()->with('api_token')->andReturn('query-token');
        $request->shouldReceive('input', 'bearerToken', 'getPassword')->never();

        $guard = $this->createGuard($provider, $request);

        $this->assertSame('query-token', $guard->getTokenForRequest());
    }

    public function testTokenLookupFallsThroughInvalidAndEmptyValues(): void
    {
        $provider = m::mock(UserProvider::class);
        $request = m::mock(Request::class)->makePartial();
        $request->shouldReceive('query')->once()->with('api_token')->andReturn(['invalid']);
        $request->shouldReceive('input')->once()->with('api_token')->andReturn('');
        $request->shouldReceive('bearerToken')->once()->andReturn('bearer-token');
        $request->shouldNotReceive('getPassword');

        $guard = $this->createGuard($provider, $request);

        $this->assertSame('bearer-token', $guard->getTokenForRequest());
    }

    public function testTokenLookupPreservesStringZero(): void
    {
        $provider = m::mock(UserProvider::class);
        $request = m::mock(Request::class)->makePartial();
        $request->shouldReceive('query')->once()->with('api_token')->andReturn('0');
        $request->shouldReceive('input', 'bearerToken', 'getPassword')->never();

        $guard = $this->createGuard($provider, $request);

        $this->assertSame('0', $guard->getTokenForRequest());
    }

    // =========================================================================
    // Context Isolation Tests (Hypervel-specific)
    // =========================================================================

    public function testDifferentTokensGetDifferentCachedUsers()
    {
        $user1 = new AuthTokenGuardTestUser;
        $user1->id = 1;
        $user2 = new AuthTokenGuardTestUser;
        $user2->id = 2;

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')
            ->with(['api_token' => 'token-a'])
            ->once()
            ->andReturn($user1);
        $provider->shouldReceive('retrieveByCredentials')
            ->with(['api_token' => 'token-b'])
            ->once()
            ->andReturn($user2);

        // First request with token-a
        $request1 = Request::create('/', 'GET', ['api_token' => 'token-a']);
        RequestContext::set($request1);
        $guard = new TokenGuard('token', $provider, $this->app);

        $this->assertSame($user1, $guard->user());

        // Switch to token-b — different token should resolve different user
        $request2 = Request::create('/', 'GET', ['api_token' => 'token-b']);
        RequestContext::set($request2);

        $this->assertSame($user2, $guard->user());
    }

    public function testEmptyTokenUsesDefaultKey()
    {
        $provider = m::mock(UserProvider::class);
        $request = Request::create('/', 'GET');

        $guard = $this->createGuard($provider, $request);
        $guard->user(); // Resolves with empty token

        // hasUser should be false (no token means no user)
        $this->assertFalse($guard->hasUser());
    }

    public function testSetUserOverridesAnUnrelatedRequestToken(): void
    {
        $user = new AuthTokenGuardTestUser;
        $user->id = 1;

        $provider = m::mock(UserProvider::class);
        $provider->shouldNotReceive('retrieveByCredentials');
        $request = Request::create('/', 'GET', ['api_token' => 'my-token']);

        $guard = $this->createGuard($provider, $request);
        $guard->setUser($user);
        RequestContext::set(Request::create('/', 'GET', ['api_token' => 'different-token']));

        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->user());
    }

    public function testForgetUserRestoresNormalTokenAuthentication(): void
    {
        $explicitUser = new AuthTokenGuardTestUser;
        $explicitUser->id = 1;
        $tokenUser = new AuthTokenGuardTestUser;
        $tokenUser->id = 2;

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')
            ->once()
            ->with(['api_token' => 'my-token'])
            ->andReturn($tokenUser);
        $request = Request::create('/', 'GET', ['api_token' => 'my-token']);

        $guard = $this->createGuard($provider, $request);
        $guard->setUser($explicitUser);

        $this->assertTrue($guard->hasUser());
        $this->assertSame($explicitUser, $guard->user());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
        $this->assertSame($tokenUser, $guard->user());

        RequestContext::set(Request::create('/'));
        $this->assertNull($guard->user());
    }

    public function testExplicitUsersAreIsolatedByGuard(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldNotReceive('retrieveByCredentials');
        $request = Request::create('/', 'GET', ['api_token' => 'request-token']);
        RequestContext::set($request);

        $firstUser = new AuthTokenGuardTestUser;
        $firstUser->id = 1;
        $secondUser = new AuthTokenGuardTestUser;
        $secondUser->id = 2;

        $firstGuard = new TokenGuard('first', $provider, $this->app);
        $secondGuard = new TokenGuard('second', $provider, $this->app);
        $firstGuard->setUser($firstUser);
        $secondGuard->setUser($secondUser);

        $this->assertSame($firstUser, $firstGuard->user());
        $this->assertSame($secondUser, $secondGuard->user());
    }

    public function testExplicitUsersAreIsolatedBetweenCoroutines(): void
    {
        $provider = m::mock(UserProvider::class);
        $provider->shouldNotReceive('retrieveByCredentials');
        $guard = new TokenGuard('token', $provider, $this->app);

        [$first, $second] = parallel([
            function () use ($guard): int {
                $user = new AuthTokenGuardTestUser;
                $user->id = 1;
                $guard->setUser($user);
                usleep(5000);

                return $guard->user()->id;
            },
            function () use ($guard): int {
                $user = new AuthTokenGuardTestUser;
                $user->id = 2;
                $guard->setUser($user);
                usleep(5000);

                return $guard->user()->id;
            },
        ]);

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertNull(CoroutineContext::get('__auth.guards.token.user.explicit'));
    }

    public function testAuthContextKeysIncludeOnlyDurableExplicitState(): void
    {
        $provider = m::mock(UserProvider::class);
        $guard = $this->createGuard(
            $provider,
            Request::create('/', 'GET', ['api_token' => 'request-token']),
        );

        $this->assertSame([
            '__auth.guards.token.user.explicit',
        ], $guard->getAuthContextKeys());
    }

    public function testChangingRequestTokenChangesWhichCachedUserIsSeen()
    {
        $user1 = new AuthTokenGuardTestUser;
        $user1->id = 1;

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')
            ->with(['api_token' => 'token-a'])
            ->once()
            ->andReturn($user1);

        $request1 = Request::create('/', 'GET', ['api_token' => 'token-a']);
        RequestContext::set($request1);

        $guard = new TokenGuard('token', $provider, $this->app);
        $this->assertSame($user1, $guard->user());

        // Change to a request with no token — guard should not see user1
        $request2 = Request::create('/', 'GET');
        RequestContext::set($request2);

        $this->assertNull($guard->user());
    }
}

class AuthTokenGuardTestUser implements Authenticatable
{
    public int $id;

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
