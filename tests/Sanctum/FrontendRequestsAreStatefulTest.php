<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Http\Middleware\AuthenticateSession;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\PersonalAccessToken;
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
            'database.default' => 'testing',
            'sanctum.stateful' => ['localhost'],
        ]);
        
        $this->defineRoutes();
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

    public function testMiddlewareKeepsSessionLoggedInWhenSanctumRequestChangesPassword(): void
    {
        $user = new User();
        $user->id = 1;
        $user->email = 'test@example.com';
        $user->password = password_hash('password', PASSWORD_DEFAULT);
        
        $this->mockUserProvider($user);
        $this->actingAs($user, 'web');
        
        // Initial web request
        $response = $this->getJson('/web/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
        
        // Sanctum API request
        $response = $this->getJson('/sanctum/api/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
        
        // Change password via API
        $response = $this->postJson('/sanctum/api/password', [], [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
        
        // Should still be authenticated
        $response = $this->getJson('/sanctum/api/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
    }

    /**
     * @dataProvider sanctumGuardsDataProvider
     */
    public function testMiddlewareCanDeauthorizeValidUserUsingActingAsAfterPasswordChangeFromSanctumGuard(?string $guard): void
    {
        $user = new User();
        $user->id = 1;
        $user->email = 'test@example.com';
        $user->password = password_hash('password', PASSWORD_DEFAULT);
        
        $this->mockUserProvider($user);
        
        Sanctum::actingAs($user, ['*'], $guard ?? 'sanctum');
        
        $response = $this->getJson('/web/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
        
        $response = $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
        
        // Change password
        $user->password = password_hash('laravel', PASSWORD_DEFAULT);
        
        // Should be unauthorized after password change
        $response = $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertStatus(401);
    }

    public function testMiddlewareRemovesPasswordHashAfterSessionIsClearedDuringRequest(): void
    {
        $user = new User();
        $user->id = 1;
        $user->email = 'test@example.com';
        $user->password = password_hash('password', PASSWORD_DEFAULT);
        
        $this->mockUserProvider($user);
        $this->actingAs($user, 'web');
        
        $response = $this->getJson('/web/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()->assertJson(['email' => $user->email]);
        
        $response = $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()
            ->assertJson(['email' => $user->email])
            ->assertSessionHas('password_hash_web', $user->getAuthPassword());
        
        $response = $this->getJson('/sanctum/api/logout', [
            'origin' => config('app.url'),
        ]);
        $response->assertOk()
            ->assertJson(['message' => 'logged out'])
            ->assertSessionMissing('password_hash_web');
    }

    public static function sanctumGuardsDataProvider(): array
    {
        return [
            [null],
            ['web'],
        ];
    }

    /**
     * Mock the user provider to return our test user
     */
    protected function mockUserProvider(User $user): void
    {
        $provider = $this->createMock(\Hypervel\Auth\Contracts\UserProvider::class);
        $provider->method('retrieveById')->willReturn($user);
        $provider->method('getModel')->willReturn(User::class);
        
        $authManager = $this->app->get(AuthFactory::class);
        if (method_exists($authManager, 'setProvider')) {
            $authManager->setProvider('users', $provider);
        }
    }
}