<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\TestUser;

/**
 * @internal
 * @coversNothing
 */
class AuthenticateRequestsTest extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set([
            'app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF',
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
            'sanctum.stateful' => ['localhost', '127.0.0.1'],
            'sanctum.guard' => ['web'],
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/sanctum/api/user', function () {
            $user = auth('sanctum')->user();

            if (! $user) {
                abort(401);
            }

            return response()->json(['email' => $user->email]);
        });

        $router->get('/sanctum/web/user', function () {
            $user = auth('sanctum')->user();

            if (! $user) {
                abort(401);
            }

            return response()->json(['email' => $user->email]);
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Context::destroyAll();
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

    public function testCanAuthorizeValidUserUsingAuthorizationHeader(): void
    {
        // Create a user in the database
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        // Create token
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test Token',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|test',
        ])->getJson('/sanctum/api/user');

        $response->assertOk()
            ->assertJson(['email' => $user->email]);
    }

    /**
     * @dataProvider sanctumGuardsDataProvider
     */
    public function testCanAuthorizeValidUserUsingSanctumActingAs(?string $guard): void
    {
        // Create a user in the database
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        Sanctum::actingAs($user, ['*'], $guard ?? 'sanctum');

        $response = $this->getJson('/sanctum/api/user');

        $response->assertOk()
            ->assertJson(['email' => $user->email]);
    }

    public static function sanctumGuardsDataProvider(): array
    {
        return [
            [null],
            ['web'],
        ];
    }

    public function testCannotAuthorizeWithInvalidToken(): void
    {
        // Create a user in the database
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        // Create token
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test Token',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);

        // Try with wrong token secret
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|wrong-token',
        ])->getJson('/sanctum/api/user');

        $response->assertUnauthorized();
    }

    public function testCannotAuthorizeWithNonExistentToken(): void
    {
        // Try with a token that doesn't exist
        $response = $this->withHeaders([
            'Authorization' => 'Bearer 999|some-random-token',
        ])->getJson('/sanctum/api/user');

        $response->assertUnauthorized();
    }

    public function testCannotAuthorizeWithoutToken(): void
    {
        // Try without any authorization header
        $response = $this->getJson('/sanctum/api/user');

        $response->assertUnauthorized();
    }

    public function testCannotAuthorizeWithMalformedToken(): void
    {
        // Try with various malformed tokens
        $malformedTokens = [
            'Bearer invalid-format',
            'Bearer |no-id',
            'Bearer no-pipe',
            'InvalidBearer 1|test',
            '',
        ];

        foreach ($malformedTokens as $token) {
            $headers = $token ? ['Authorization' => $token] : [];

            $response = $this->withHeaders($headers)->getJson('/sanctum/api/user');

            $response->assertUnauthorized();
        }
    }

    public function testCannotAuthorizeWithExpiredToken(): void
    {
        // Create a user in the database
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        // Create an expired token
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test Token',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
            'expires_at' => now()->subHour(), // Expired 1 hour ago
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->id . '|test',
        ])->getJson('/sanctum/api/user');

        $response->assertUnauthorized();
    }
}
