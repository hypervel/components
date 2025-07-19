<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactory;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Http\Middleware\AuthenticateSession;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Session\Contracts\Session;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\User;

/**
 * @internal
 * @coversNothing
 */
class FrontendRequestsAreStatefulTest extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(SanctumServiceProvider::class);

        // Configure test environment
        config([
            'app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF',
            'app.url' => 'http://localhost',
            'auth.guards.sanctum' => [
                'driver' => 'sanctum',
                'provider' => 'users',
            ],
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
            'auth.providers.users.model' => User::class,
            'auth.providers.users.driver' => 'eloquent',
            'database.default' => 'testing',
            'sanctum.stateful' => ['localhost'],
            'sanctum.guard' => ['web'],
        ]);

        $this->createUsersTable();
        $this->defineRoutes();
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

    protected function defineRoutes(): void
    {
        $webMiddleware = ['web', AuthenticateSession::class];
        $apiMiddleware = [EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'];

        Route::get('/sanctum/api/user', function (RequestInterface $request) {
            $authFactory = $this->app->get(AuthFactory::class);
            $user = $authFactory->guard('sanctum')->user();

            if (! $user) {
                abort(401);
            }

            return response()->json(['email' => $user->email]);
        }, ['middleware' => $apiMiddleware]);

        Route::post('/sanctum/api/password', function (RequestInterface $request) {
            $authFactory = $this->app->get(AuthFactory::class);
            $user = $authFactory->guard('sanctum')->user();

            if (! $user) {
                abort(401);
            }

            // Update password
            $user->password = password_hash('laravel', PASSWORD_DEFAULT);

            // Save to update password_hash in session
            if (method_exists($user, 'save')) {
                $user->save();
            }

            return response()->json(['email' => $user->email]);
        }, ['middleware' => $apiMiddleware]);

        Route::get('/sanctum/web/user', function (RequestInterface $request) {
            $authFactory = $this->app->get(AuthFactory::class);
            $user = $authFactory->guard('sanctum')->user();

            if (! $user) {
                abort(401);
            }

            return response()->json(['email' => $user->email]);
        }, ['middleware' => $apiMiddleware]);

        Route::get('/web/user', function (RequestInterface $request) {
            $authFactory = $this->app->get(AuthFactory::class);
            $user = $authFactory->guard('web')->user();

            if (! $user) {
                abort(401);
            }

            return response()->json(['email' => $user->email]);
        }, ['middleware' => $webMiddleware]);

        Route::get('/sanctum/api/logout', function () {
            $authFactory = $this->app->get(AuthFactory::class);
            $session = $this->app->get(Session::class);

            if (method_exists($authFactory->guard('web'), 'logout')) {
                $authFactory->guard('web')->logout();
            }

            $session->flush();

            return response()->json(['message' => 'logged out']);
        }, ['middleware' => $apiMiddleware]);
    }

    /**
     * Create a user in the database.
     */
    protected function createUser(array $attributes = []): User
    {
        $defaults = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ];

        $attributes = array_merge($defaults, $attributes);

        // Create user model
        $user = new User();
        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        // Save to database if model supports it
        if (method_exists($user, 'save')) {
            $user->save();
        } else {
            // Manual insert for stub model
            $id = $this->app->get('db')->connection()->table('users')->insertGetId($attributes);
            $user->id = $id;
        }

        return $user;
    }

    public function testMiddlewareKeepsSessionLoggedInWhenSanctumRequestChangesPassword(): void
    {
        $user = $this->createUser();

        // Login using web guard
        $this->actingAs($user, 'web');

        // Initial web request
        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/web/user');
        $response->assertOk()->assertJson(['email' => $user->email]);

        // Sanctum API request
        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/sanctum/api/user');
        $response->assertOk()->assertJson(['email' => $user->email]);

        // Change password via API
        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->postJson('/sanctum/api/password');
        $response->assertOk()->assertJson(['email' => $user->email]);

        // Should still be authenticated
        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/sanctum/api/user');
        $response->assertOk()->assertJson(['email' => $user->email]);
    }

    /**
     * @dataProvider sanctumGuardsDataProvider
     */
    public function testMiddlewareCanDeauthorizeValidUserUsingActingAsAfterPasswordChangeFromSanctumGuard(?string $guard): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user, ['*'], $guard ?? 'sanctum');

        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/web/user');
        $response->assertOk()->assertJson(['email' => $user->email]);

        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/sanctum/web/user');
        $response->assertOk()->assertJson(['email' => $user->email]);

        // Change password directly on the user object
        $user->password = password_hash('laravel', PASSWORD_DEFAULT);

        // The next request should be unauthorized because password changed
        // Note: This behavior might vary based on how the session middleware is configured
        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/sanctum/web/user');

        // This test expects unauthorized, but the actual behavior depends on
        // whether the session password hash check is properly implemented
        $response->assertStatus(401);
    }

    public static function sanctumGuardsDataProvider(): array
    {
        return [
            [null],
            ['web'],
        ];
    }

    public function testMiddlewareRemovesPasswordHashAfterSessionIsClearedDuringRequest(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'web');

        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/web/user');
        $response->assertOk()->assertJson(['email' => $user->email]);

        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/sanctum/web/user');
        $response->assertOk()
            ->assertJson(['email' => $user->email])
            ->assertSessionHas('password_hash_web');

        $response = $this->withHeaders([
            'origin' => config('app.url'),
        ])->getJson('/sanctum/api/logout');
        $response->assertOk()
            ->assertJson(['message' => 'logged out'])
            ->assertSessionMissing('password_hash_web');
    }
}
