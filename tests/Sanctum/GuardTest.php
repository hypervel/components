<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\Authenticatable as AuthenticatableTrait;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Events\TokenAuthenticated;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Sanctum\TransientToken;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\TestUser;
use Hypervel\Tests\Sanctum\Fixtures\User as SanctumTestUser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class GuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
        $this->defineTestRoutes();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'auth.guards.sanctum' => [
                'driver' => 'sanctum',
                'provider' => 'users',
                'session_guards' => ['web'],
            ],
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
            'auth.providers.users.model' => TestUser::class,
            'auth.providers.users.driver' => 'eloquent',
        ]);
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
        $this->app->make('db')->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    /**
     * Create the alternate users table for provider matching tests.
     */
    protected function createSanctumTestUsersTable(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->create('sanctum_test_users', function ($table) {
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

    /**
     * Expect the invalid session guards configuration exception.
     */
    protected function expectInvalidSessionGuardsConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Auth guard [sanctum] uses the sanctum driver but does not declare a valid session guards list. '
            . 'Set auth.guards.sanctum.session_guards to an array of session guard names, or [] to disable stateful session authentication.'
        );
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
        $authManager = $this->app->make('auth');
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

    public function testEmptySessionGuardsIsTokenOnly(): void
    {
        $this->app->make('config')->set('auth.guards.sanctum.session_guards', []);

        $sessionUser = TestUser::create([
            'name' => 'Session User',
            'email' => 'session@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        $this->app->make('auth')->guard('web')->setUser($sessionUser);

        $this->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);

        [$tokenUser, $token, $plainToken] = $this->createUserWithToken(plainTextToken: 'token-only');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $tokenUser->id,
                'token_id' => $token->id,
            ]);
    }

    public function testMissingSessionGuardsThrowsInstructiveError(): void
    {
        $this->app->make('config')->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $this->app->make('auth')->forgetGuards();

        $this->expectInvalidSessionGuardsConfiguration();

        $this->app->make('auth')->guard('sanctum');
    }

    public function testNonArraySessionGuardsThrowsInstructiveError(): void
    {
        $this->app->make('config')->set('auth.guards.sanctum.session_guards', 'web');
        $this->app->make('auth')->forgetGuards();

        $this->expectInvalidSessionGuardsConfiguration();

        $this->app->make('auth')->guard('sanctum');
    }

    #[DataProvider('invalidSessionGuardsDataProvider')]
    public function testInvalidSessionGuardEntriesThrowInstructiveError(array $sessionGuards): void
    {
        $this->app->make('config')->set('auth.guards.sanctum.session_guards', $sessionGuards);
        $this->app->make('auth')->forgetGuards();

        $this->expectInvalidSessionGuardsConfiguration();

        $this->app->make('auth')->guard('sanctum');
    }

    public static function invalidSessionGuardsDataProvider(): array
    {
        return [
            [[123]],
            [['']],
        ];
    }

    public function testNonStatefulSessionGuardThrows(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.guards.sanctum.session_guards', ['other-sanctum']);
        $config->set('auth.guards.other-sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => [],
        ]);
        $this->app->make('auth')->forgetGuards();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth guard [sanctum] lists [other-sanctum] in session_guards, but that guard is not a stateful guard.');

        $this->withoutExceptionHandling()->getJson('/test/user');
    }

    public function testStatefulUserMustMatchProvider(): void
    {
        $this->createSanctumTestUsersTable();

        $config = $this->app->make('config');
        $config->set('auth.providers.admins', [
            'driver' => 'eloquent',
            'model' => SanctumTestUser::class,
        ]);
        $config->set('auth.guards.web.provider', 'admins');
        $this->app->make('auth')->forgetGuards();

        $sessionUser = SanctumTestUser::create([
            'name' => 'Session User',
            'email' => 'session@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        $this->app->make('auth')->guard('web')->setUser($sessionUser);

        [$tokenUser, $token, $plainToken] = $this->createUserWithToken(plainTextToken: 'provider-match');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $tokenUser->id,
                'token_id' => $token->id,
            ]);
    }

    public function testSecondListedSessionGuardIsTried(): void
    {
        $config = $this->app->make('config');
        $config->set('auth.guards.admin', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $config->set('auth.guards.sanctum.session_guards', ['admin', 'web']);
        $this->app->make('auth')->forgetGuards();

        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test-second@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        $this->app->make('auth')->guard('web')->setUser($user);

        $this->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'token_class' => TransientToken::class,
            ]);
    }

    public function testNullUserFromStatefulGuardFallsThroughToLaterSessionGuard(): void
    {
        $auth = $this->app->make('auth');
        $auth->extend('null-stateful', fn () => new NullUserStatefulGuard);

        $config = $this->app->make('config');
        $config->set('auth.guards.null-stateful', [
            'driver' => 'null-stateful',
            'provider' => 'users',
        ]);
        $config->set('auth.guards.sanctum.session_guards', ['null-stateful', 'web']);
        $auth->forgetGuards();

        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test-null-stateful@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        $auth->guard('web')->setUser($user);

        $this->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'token_class' => TransientToken::class,
            ]);
    }

    public function testNullUserFromStatefulGuardFallsThroughToBearerToken(): void
    {
        $auth = $this->app->make('auth');
        $auth->extend('null-stateful', fn () => new NullUserStatefulGuard);

        $config = $this->app->make('config');
        $config->set('auth.guards.null-stateful', [
            'driver' => 'null-stateful',
            'provider' => 'users',
        ]);
        $config->set('auth.guards.sanctum.session_guards', ['null-stateful']);
        $auth->forgetGuards();

        [$user, $token, $plainToken] = $this->createUserWithToken(plainTextToken: 'null-stateful-token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'token_id' => $token->id,
            ]);
    }

    public function testAuthenticationWithTokenFailsIfConfiguredExpirationHasPassed(): void
    {
        $this->app->make('config')->set('sanctum.expiration', 60);
        [$user, $token, $plainToken] = $this->createUserWithToken();
        $token->forceFill(['created_at' => now()->subMinutes(61)])->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user');

        $response->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
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

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testAuthenticationWithBadTokenHashDoesNotUpdateLastUsedAt(): void
    {
        [$user, $token] = $this->createUserWithToken();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|wrong',
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testProviderMismatchDoesNotUpdateLastUsedAt(): void
    {
        $this->createSanctumTestUsersTable();

        $user = SanctumTestUser::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        $token = $user->tokens()->create([
            'name' => 'Other Token',
            'token' => hash('sha256', 'provider-mismatch'),
            'abilities' => ['*'],
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|provider-mismatch',
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testMissingTokenableDoesNotUpdateLastUsedAt(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();
        $user->delete();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testTokenableWithoutApiTokensDoesNotUpdateLastUsedAt(): void
    {
        $this->app->make('config')->set('auth.providers.users.model', TokenlessUser::class);
        $this->app->make('auth')->forgetGuards();

        $user = TokenlessUser::create([
            'name' => 'Tokenless User',
            'email' => 'tokenless@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => TokenlessUser::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'Tokenless Token',
            'token' => hash('sha256', 'tokenless'),
            'abilities' => ['*'],
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|tokenless',
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
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

    public function testSuccessfulAuthenticationTracksLastUsedAtByDefault(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function testCancelledLastUsedAtUpdateDoesNotFailAuthentication(): void
    {
        [$user, $token, $plainToken] = $this->createUserWithToken();

        PersonalAccessToken::updating(static fn (): false => false);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/last-used-at')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'last_used_at' => null,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testSuccessfulAuthenticationDoesNotTrackLastUsedAtWhenDisabled(): void
    {
        $this->app->make('config')->set('sanctum.last_used_at', false);
        [$user, $token, $plainToken] = $this->createUserWithToken();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testSuccessfulAuthenticationWritesLastUsedAtOnEachRequestWhenCachingIsDisabled(): void
    {
        $this->freezeTime();
        [$user, $token, $plainToken] = $this->createUserWithToken();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')->assertOk();

        $firstLastUsedAt = $token->fresh()->last_used_at;

        $this->travel(1)->second();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')->assertOk();

        $this->assertTrue($token->fresh()->last_used_at->isAfter($firstLastUsedAt));
    }

    public function testTokenAuthenticationDispatchesEvent(): void
    {
        $tokenAuthenticatedFired = false;
        $lastUsedAtDuringEvent = true;

        $this->app->make(Dispatcher::class)->listen(TokenAuthenticated::class, function (TokenAuthenticated $event) use (&$tokenAuthenticatedFired, &$lastUsedAtDuringEvent) {
            $tokenAuthenticatedFired = true;
            $lastUsedAtDuringEvent = $event->token->last_used_at;
        });

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
        $this->assertNull($lastUsedAtDuringEvent);
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function testThrowingTokenAuthenticatedListenerDoesNotUpdateLastUsedAt(): void
    {
        $this->app->make(Dispatcher::class)->listen(
            TokenAuthenticated::class,
            static function (): never {
                throw new RuntimeException('Listener failed.');
            }
        );

        [$user, $token, $plainToken] = $this->createUserWithToken();
        $this->withoutExceptionHandling();

        try {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $plainToken,
            ])->getJson('/test/user');
            $this->fail('Expected listener exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Listener failed.', $exception->getMessage());
        }

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testCustomTokenModelCanOverrideLastUsedAtUpdate(): void
    {
        Sanctum::usePersonalAccessTokenModel(CustomTrackingPersonalAccessToken::class);
        [$user, $token, $plainToken] = $this->createUserWithToken();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test/user')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);

        $token = $token->fresh();

        $this->assertSame('Tracked by custom model', $token->name);
        $this->assertNull($token->last_used_at);
    }

    #[DataProvider('invalidTokenDataProvider')]
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

        $this->assertNull($token->fresh()->last_used_at);
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

class CustomTrackingPersonalAccessToken extends PersonalAccessToken
{
    public function updateLastUsedAt(): void
    {
        $this->forceFill(['name' => 'Tracked by custom model'])->save();
    }
}

class NullUserStatefulGuard implements StatefulGuard
{
    public function attempt(array $credentials = [], bool $remember = false): bool
    {
        return false;
    }

    public function once(array $credentials = []): bool
    {
        return false;
    }

    public function login(Authenticatable $user, bool $remember = false): void
    {
    }

    public function loginUsingId(mixed $id, bool $remember = false): Authenticatable|false
    {
        return false;
    }

    public function onceUsingId(mixed $id): Authenticatable|false
    {
        return false;
    }

    public function viaRemember(): bool
    {
        return false;
    }

    public function logout(): void
    {
    }

    public function check(): bool
    {
        return true;
    }

    public function guest(): bool
    {
        return false;
    }

    public function user(): ?Authenticatable
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        return $this;
    }
}

class TokenlessUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected ?string $table = 'users';

    protected array $fillable = ['name', 'email', 'password'];
}
