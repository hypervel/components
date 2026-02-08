<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\TestUser;

/**
 * @internal
 * @coversNothing
 */
class SimpleGuardTest extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SanctumServiceProvider::class);

        $this->app->get(Repository::class)
            ->set([
                'auth.guards.sanctum' => [
                    'driver' => 'sanctum',
                    'provider' => 'users',
                ],
                'auth.providers.users.model' => TestUser::class,
                'auth.providers.users.driver' => 'eloquent',
            ]);

        // Create users table
        $this->app->get('db')->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        // Define a test route
        Route::get('/test-sanctum-auth', function () {
            $user = auth('sanctum')->user();
            return response()->json([
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'token_id' => $user?->currentAccessToken()?->id,
            ]);
        });
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

    public function testAuthenticationWithTokenIfNoSessionPresent(): void
    {
        // Create a user
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        // Create token using relationship
        $token = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);

        $plainToken = $token->id . '|test';

        // Make request with authorization header
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
        ])->getJson('/test-sanctum-auth');

        // Assert the response
        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'token_id' => $token->id,
            ]);
    }
}
