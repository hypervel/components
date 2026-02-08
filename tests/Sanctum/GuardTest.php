<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\AuthManager;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Sanctum\TransientToken;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\TestUser;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class GuardTest extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SanctumServiceProvider::class);

        $this->app->get('config')
            ->set([
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
                'sanctum.guard' => ['web'],
            ]);

        $this->createUsersTable();
        $this->defineTestRoutes();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Context::destroy('__sanctum.acting_as_user');
        Context::destroy('__sanctum.acting_as_guard');

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
     * Create the users table for testing.
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

    /**
     * Define test routes.
     */
    protected function defineTestRoutes(): void
    {
        Route::get('/test/user', function () {
            $user = auth('sanctum')->user();
            return response()->json([
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'token_id' => $user?->currentAccessToken()?->id ?? null,
                'token_class' => $user?->currentAccessToken() ? get_class($user->currentAccessToken()) : null,
                'can_foo' => $user?->tokenCan('foo'),
            ]);
        });

        Route::get('/test/custom-header', function () {
            $user = auth('sanctum')->user();
            return response()->json([
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
            ]);
        });

        Route::get('/test/last-used-at', function () {
            $user = auth('sanctum')->user();
            $token = $user?->currentAccessToken();
            return response()->json([
                'authenticated' => $user !== null,
                'last_used_at' => $token?->last_used_at?->toISOString(),
            ]);
        });
    }

    /**
     * Helper method to create a user with a token.
     */
    protected function createUserWithToken(array $abilities = ['*'], ?string $plainTextToken = 'test'): array
    {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        $token = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
        ]);

        return [$user, $token, $token->id . '|' . $plainTextToken];
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

        // Make request without token - should use web guard
        $response = $this->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'token_class' => TransientToken::class,
                'can_foo' => true,
            ]);
    }

    public function testAuthenticationWithTokenIfNoSessionPresent(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'token_id' => $token->id,
            ]);
    }

    public function testAuthenticationWithTokenFailsIfExpired(): void
    {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        // Instead of relying on sanctum.expiration config, use expires_at
        $token = $user->tokens()->create([
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->subMinute(), // Already expired
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|test',
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    public function testAuthenticationWithTokenFailsIfExpiresAtHasPassed(): void
    {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        $token = $user->tokens()->create([
            'name' => 'Test',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->subMinutes(60),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|test',
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    public function testAuthenticationWithTokenSucceedsIfExpiresAtNotPassed(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();

        // Update token to have future expiration
        $token->update(['expires_at' => now()->addMinutes(60)]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'token_id' => $token->id,
            ]);

        // Check that last_used_at was updated
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/last-used-at');

        $response2->assertOk();
        $data = $response2->json();
        $this->assertNotNull($data['last_used_at']);
    }

    public function testTokenAuthenticationDispatchesEvent(): void
    {
        $tokenAuthenticatedFired = false;

        // Get the real event dispatcher
        $realDispatcher = $this->app->get(EventDispatcherInterface::class);

        // Create a partial mock that delegates to the real dispatcher
        $events = m::mock($realDispatcher);
        $events->makePartial(); // This makes it a partial mock

        // Only spy on dispatch calls, don't change behavior
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use ($realDispatcher, &$tokenAuthenticatedFired) {
                if ($event instanceof TokenAuthenticated) {
                    $tokenAuthenticatedFired = true;
                }
                // Call the real method
                return $realDispatcher->dispatch($event);
            });

        $this->app->instance(EventDispatcherInterface::class, $events);

        [$user, $token, $plainToken] = $this->createUserWithToken();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);

        $this->assertTrue($tokenAuthenticatedFired, 'TokenAuthenticated event was not fired');
    }

    /**
     * @dataProvider invalidTokenDataProvider
     */
    public function testAuthenticationFailsWithInvalidTokenFormat(string $invalidToken): void
    {
        $headers = $invalidToken ? ['Authorization' => $invalidToken] : [];

        $response = $this->withHeaders($headers)->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    public static function invalidTokenDataProvider(): array
    {
        return [
            [''],
            ['Bearer'],
            ['Bearer '],
            ['Bearer |test'],
            ['Bearer 1ABC|test'],
            ['Bearer 1ABC|'],
            ['Bearer 1,2|test'],
            ['InvalidBearer 1|test'],
            ['1|test'], // Missing Bearer prefix
        ];
    }

    public function testAuthenticationFailsIfCallbackReturnsFalse(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();

        // Set callback that returns false
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            return false;
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    public function testAuthenticationWithCustomTokenHeader(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();

        // Set custom token retrieval callback
        Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->header('X-Auth-Token');
        });

        // Define a route that uses the custom header
        Route::get('/test/custom-auth', function () {
            $user = auth('sanctum')->user();
            return response()->json([
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
            ]);
        });

        $response = $this->withHeaders([
            'X-Auth-Token' => $plainToken,
        ])->getJson('/test/custom-auth');

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);
    }

    public function testAuthenticationFailsWhenCustomHeaderNotPresent(): void
    {
        // Set custom token retrieval callback
        Sanctum::getAccessTokenFromRequestUsing(function ($request) {
            return $request->header('X-Auth-Token');
        });

        $response = $this->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    public function testActingAsUserAuthentication(): void
    {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        // Use Sanctum::actingAs
        Sanctum::actingAs($user, ['read', 'write']);

        // Test route that checks abilities
        Route::get('/test/abilities', function () {
            $user = auth('sanctum')->user();
            return response()->json([
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
                'can_read' => $user?->tokenCan('read'),
                'can_write' => $user?->tokenCan('write'),
                'can_delete' => $user?->tokenCan('delete'),
            ]);
        });

        $response = $this->getJson('/test/abilities');

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'can_read' => true,
                'can_write' => true,
                'can_delete' => false,
            ]);
    }
}
