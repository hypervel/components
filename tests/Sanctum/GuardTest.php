<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Feature;

use DateTimeInterface;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactory;
use Hypervel\Auth\Contracts\Guard;
use Hypervel\Auth\Contracts\UserProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumGuard;
use Hypervel\Sanctum\TransientToken;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\User;
use Mockery;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class GuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        config([
            'auth.guards.sanctum' => [
                'driver' => 'sanctum',
                'provider' => 'users',
            ],
            'auth.providers.users.model' => User::class,
            'database.default' => 'testing',
            'sanctum.guard' => ['web'],
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        Mockery::close();
        Context::destroy();
        Sanctum::$accessTokenRetrievalCallback = null;
        Sanctum::$accessTokenAuthenticationCallback = null;
    }

    public function testAuthenticationIsAttemptedWithWebMiddleware(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        $fakeUser = new User();
        $fakeUser->id = 1;
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(true);
        $webGuard->shouldReceive('user')->once()->andReturn($fakeUser);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertSame($fakeUser, $user);
        $this->assertInstanceOf(TransientToken::class, $user->currentAccessToken());
        $this->assertTrue($user->tokenCan('foo'));
    }

    public function testAuthenticationIsAttemptedWithTokenIfNoSessionPresent(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer test');
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertNull($user);
    }

    public function testAuthenticationWithTokenFailsIfExpired(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        // Set expiration to 1 minute
        $guard = new SanctumGuard('sanctum', $provider, $request, null, 1);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $user = new User();
        $user->id = 1;
        
        // Create expired token
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'created_at' => now()->subMinutes(60),
        ]);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertNull($user);
    }

    public function testAuthenticationWithTokenFailsIfExpiresAtHasPassed(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $user = new User();
        $user->id = 1;
        
        // Create token with expired expires_at
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->subMinutes(60),
        ]);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertNull($user);
    }

    public function testAuthenticationWithTokenSucceedsIfExpiresAtNotPassed(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $user = new User();
        $user->id = 1;
        
        // Create token with future expires_at
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->addMinutes(60),
        ]);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        $provider->shouldReceive('getModel')->andReturn(User::class);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $returnedUser = $guard->user();
        
        $this->assertEquals($user->id, $returnedUser->id);
        $this->assertEquals($token->id, $returnedUser->currentAccessToken()->id);
        $this->assertInstanceOf(DateTimeInterface::class, $returnedUser->currentAccessToken()->last_used_at);
    }

    public function testAuthenticationIsSuccessfulWithTokenIfNoSessionPresent(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        $events = Mockery::mock(EventDispatcherInterface::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, $events, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $user = new User();
        $user->id = 1;
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        $provider->shouldReceive('getModel')->andReturn(User::class);
        
        $events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(TokenAuthenticated::class));
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $returnedUser = $guard->user();
        
        $this->assertEquals($user->id, $returnedUser->id);
        $this->assertEquals($token->id, $returnedUser->currentAccessToken()->id);
        $this->assertInstanceOf(DateTimeInterface::class, $returnedUser->currentAccessToken()->last_used_at);
    }

    /**
     * @dataProvider invalidTokenDataProvider
     */
    public function testAuthenticationWithTokenFailsIfTokenHasInvalidFormat(string $invalidToken): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn($invalidToken);
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertNull($user);
    }

    public function testAuthenticationFailsIfCallbackReturnsFalse(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $user = new User();
        $user->id = 1;
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        $provider->shouldReceive('getModel')->andReturn(User::class);
        
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            $this->assertInstanceOf(PersonalAccessToken::class, $accessToken);
            $this->assertTrue($isValid);
            
            return false;
        });
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertNull($user);
    }

    public function testAuthenticationIsSuccessfulWithTokenInCustomHeader(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $user = new User();
        $user->id = 1;
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $request->shouldReceive('header')
            ->with('X-Auth-Token', null)
            ->andReturn($token->id . '|test');
        
        Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->header('X-Auth-Token');
        });
        
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        $provider->shouldReceive('getModel')->andReturn(User::class);
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $returnedUser = $guard->user();
        
        $this->assertEquals($user->id, $returnedUser->id);
        $this->assertEquals($token->id, $returnedUser->currentAccessToken()->id);
    }

    public function testAuthenticationFailsWithTokenInAuthorizationHeaderWhenUsingCustomHeader(): void
    {
        $factory = Mockery::mock(AuthFactory::class);
        $request = Mockery::mock(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $webGuard = Mockery::mock(Guard::class);
        
        $factory->shouldReceive('guard')
            ->with('web')
            ->andReturn($webGuard);
        
        $webGuard->shouldReceive('check')->once()->andReturn(false);
        
        $request->shouldReceive('header')
            ->with('X-Auth-Token', null)
            ->andReturn(null);
        
        Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->header('X-Auth-Token');
        });
        
        // Set factory in context for guard to use
        $this->app->instance(AuthFactory::class, $factory);
        
        $user = $guard->user();
        
        $this->assertNull($user);
    }

    public static function invalidTokenDataProvider(): array
    {
        return [
            [''],
            ['|'],
            ['test'],
            ['|test'],
            ['1ABC|test'],
            ['1ABC|'],
            ['1,2|test'],
            ['Bearer'],
            ['Bearer |test'],
            ['Bearer 1,2|test'],
            ['Bearer 1ABC|test'],
            ['Bearer 1ABC|'],
        ];
    }
}