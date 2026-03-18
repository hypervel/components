<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\TokenGuard;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class AuthTokenGuardTest extends TestCase
{
    /**
     * Create a TokenGuard with a request bound to a fresh container.
     */
    protected function createGuard(
        UserProvider $provider,
        Request $request,
        string $inputKey = 'api_token',
        string $storageKey = 'api_token',
        bool $hash = false,
    ): TokenGuard {
        $container = Container::setInstance(new Container());
        $container->instance('request', $request);

        return new TokenGuard('token', $provider, $container, $inputKey, $storageKey, $hash);
    }

    public function testUserCanBeRetrievedByQueryStringVariable()
    {
        $provider = m::mock(UserProvider::class);
        $user = new AuthTokenGuardTestUser();
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
        $user = new AuthTokenGuardTestUser();
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
        $user = new AuthTokenGuardTestUser();
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
        $user = new AuthTokenGuardTestUser();
        $user->id = 1;
        $provider->shouldReceive('retrieveByCredentials')->once()->with(['api_token' => 'custom'])->andReturn($user);
        $request = Request::create('/', 'GET', ['api_token' => 'foo']);

        $guard = $this->createGuard($provider, $request);

        // Replace the request in the container (Hypervel resolves request from container, not a stored property)
        Container::getInstance()->instance('request', Request::create('/', 'GET', ['api_token' => 'custom']));

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
        $user = new AuthTokenGuardTestUser();
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
        $user = new AuthTokenGuardTestUser();
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

    // =========================================================================
    // Context Isolation Tests (Hypervel-specific)
    // =========================================================================

    public function testDifferentTokensGetDifferentCachedUsers()
    {
        $user1 = new AuthTokenGuardTestUser();
        $user1->id = 1;
        $user2 = new AuthTokenGuardTestUser();
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

        $container = Container::setInstance(new Container());

        // First request with token-a
        $request1 = Request::create('/', 'GET', ['api_token' => 'token-a']);
        $container->instance('request', $request1);
        $guard = new TokenGuard('token', $provider, $container);

        $this->assertSame($user1, $guard->user());

        // Switch to token-b — different token should resolve different user
        $request2 = Request::create('/', 'GET', ['api_token' => 'token-b']);
        $container->instance('request', $request2);

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

    public function testSetUserOperatesPerToken()
    {
        $user = new AuthTokenGuardTestUser();
        $user->id = 1;

        $provider = m::mock(UserProvider::class);
        $request = Request::create('/', 'GET', ['api_token' => 'my-token']);

        $guard = $this->createGuard($provider, $request);
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->user());
    }

    public function testForgetUserClearsCacheForCurrentToken()
    {
        $user = new AuthTokenGuardTestUser();
        $user->id = 1;

        $provider = m::mock(UserProvider::class);
        $request = Request::create('/', 'GET', ['api_token' => 'my-token']);

        $guard = $this->createGuard($provider, $request);
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
    }

    public function testChangingRequestTokenChangesWhichCachedUserIsSeen()
    {
        $user1 = new AuthTokenGuardTestUser();
        $user1->id = 1;

        $provider = m::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')
            ->with(['api_token' => 'token-a'])
            ->once()
            ->andReturn($user1);

        $container = Container::setInstance(new Container());
        $request1 = Request::create('/', 'GET', ['api_token' => 'token-a']);
        $container->instance('request', $request1);

        $guard = new TokenGuard('token', $provider, $container);
        $this->assertSame($user1, $guard->user());

        // Change to a request with no token — guard should not see user1
        $request2 = Request::create('/', 'GET');
        $container->instance('request', $request2);

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
