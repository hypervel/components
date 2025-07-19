<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Feature;

use DateTimeInterface;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\Contracts\UserProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumGuard;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Sanctum\TransientToken;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\TestUser;
use Mockery;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class GuardTest extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->app->register(SanctumServiceProvider::class);
        
        config([
            'auth.guards.sanctum' => [
                'driver' => 'sanctum',
                'provider' => 'users',
            ],
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
            'auth.providers.users.model' => TestUser::class,
            'auth.providers.users.driver' => 'eloquent',
            'database.default' => 'testing',
            'sanctum.guard' => ['web'],
        ]);
        
        $this->createUsersTable();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        Mockery::close();
        Sanctum::$accessTokenRetrievalCallback = null;
        Sanctum::$accessTokenAuthenticationCallback = null;
    }

    /**
     * Get the migrations to run for the test.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => [
                __DIR__ . '/../../src/sanctum/database/migrations',
            ],
        ];
    }

    /**
     * Create the users table for testing
     */
    protected function createUsersTable(): void
    {
        $this->app->get('db')->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function testAuthenticationIsAttemptedWithWebMiddleware(): void
    {
        // Create a user in the database
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        // Set the user on the web guard
        $authManager = $this->app->get(AuthManager::class);
        $authManager->guard('web')->setUser($user);
        
        // Create a sanctum guard instance
        $request = $this->app->get(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertInstanceOf(TestUser::class, $authenticatedUser);
        $this->assertEquals($user->id, $authenticatedUser->id);
        $this->assertInstanceOf(TransientToken::class, $authenticatedUser->currentAccessToken());
        $this->assertTrue($authenticatedUser->tokenCan('foo'));
    }

    public function testAuthenticationWithTokenIfNoSessionPresent(): void
    {
        // Create a user and token
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        // Mock request with Bearer token
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertInstanceOf(TestUser::class, $authenticatedUser);
        $this->assertEquals($user->id, $authenticatedUser->id);
        $this->assertEquals($token->id, $authenticatedUser->currentAccessToken()->id);
    }

    public function testAuthenticationWithTokenFailsIfExpired(): void
    {
        // Create a user with expired token
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'created_at' => now()->subMinutes(60),
        ]);
        
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        
        // Create guard with 1 minute expiration
        $guard = new SanctumGuard('sanctum', $provider, $request, null, 1);
        
        $authenticatedUser = $guard->user();
        
        $this->assertNull($authenticatedUser);
    }

    public function testAuthenticationWithTokenFailsIfExpiresAtHasPassed(): void
    {
        // Create a user with token that has expires_at in the past
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->subMinutes(60),
        ]);
        
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertNull($authenticatedUser);
    }

    public function testAuthenticationWithTokenSucceedsIfExpiresAtNotPassed(): void
    {
        // Create a user with valid token
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->addMinutes(60),
        ]);
        
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertInstanceOf(TestUser::class, $authenticatedUser);
        $this->assertEquals($user->id, $authenticatedUser->id);
        $this->assertInstanceOf(DateTimeInterface::class, $authenticatedUser->currentAccessToken()->last_used_at);
    }

    public function testTokenAuthenticationDispatchesEvent(): void
    {
        // Create a user with token
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        
        $events = Mockery::mock(EventDispatcherInterface::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(TokenAuthenticated::class));
        
        $guard = new SanctumGuard('sanctum', $provider, $request, $events, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertInstanceOf(TestUser::class, $authenticatedUser);
    }

    /**
     * @dataProvider invalidTokenDataProvider
     */
    public function testAuthenticationFailsWithInvalidTokenFormat(string $invalidToken): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn($invalidToken);
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertNull($authenticatedUser);
    }

    public function testAuthenticationFailsIfCallbackReturnsFalse(): void
    {
        // Create a user with token
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('Authorization', '')
            ->andReturn('Bearer ' . $token->id . '|test');
        $request->shouldReceive('has')->with('token')->andReturn(false);
        
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        
        // Set callback that returns false
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            $this->assertInstanceOf(PersonalAccessToken::class, $accessToken);
            $this->assertTrue($isValid);
            
            return false;
        });
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertNull($authenticatedUser);
    }

    public function testAuthenticationWithCustomTokenHeader(): void
    {
        // Create a user with token
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('X-Auth-Token', null)
            ->andReturn($token->id . '|test');
        
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with($user->id)->andReturn($user);
        
        // Set custom token retrieval callback
        Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->header('X-Auth-Token');
        });
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertInstanceOf(TestUser::class, $authenticatedUser);
        $this->assertEquals($user->id, $authenticatedUser->id);
    }

    public function testAuthenticationFailsWhenCustomHeaderNotPresent(): void
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('X-Auth-Token', null)
            ->andReturn(null);
        
        $provider = Mockery::mock(UserProvider::class);
        
        // Set custom token retrieval callback
        Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->header('X-Auth-Token');
        });
        
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertNull($authenticatedUser);
    }

    public function testActingAsUserAuthentication(): void
    {
        // Create a user
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        
        // Use Sanctum::actingAs
        Sanctum::actingAs($user, ['read', 'write']);
        
        // Create guard and test authentication
        $request = $this->app->get(RequestInterface::class);
        $provider = Mockery::mock(UserProvider::class);
        $guard = new SanctumGuard('sanctum', $provider, $request, null, null);
        
        $authenticatedUser = $guard->user();
        
        $this->assertInstanceOf(TestUser::class, $authenticatedUser);
        $this->assertEquals($user->id, $authenticatedUser->id);
        $this->assertTrue($authenticatedUser->tokenCan('read'));
        $this->assertTrue($authenticatedUser->tokenCan('write'));
        $this->assertFalse($authenticatedUser->tokenCan('delete'));
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