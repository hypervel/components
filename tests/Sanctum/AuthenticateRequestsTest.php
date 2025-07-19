<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Controller;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Auth\Contracts\Factory as AuthFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Stub\User;

/**
 * @internal
 * @coversNothing
 */
class AuthenticateRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->app->register(SanctumServiceProvider::class);
        
        // Configure test environment
        config([
            'app.key' => 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF',
            'auth.guards.sanctum' => [
                'driver' => 'sanctum',
                'provider' => 'users',
            ],
            'auth.providers.users.model' => User::class,
            'database.default' => 'testing',
        ]);
        
        $this->defineRoutes();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        Context::destroy('__sanctum.acting_as_user');
        Context::destroy('__sanctum.acting_as_guard');
    }

    protected function defineRoutes(): void
    {
        $apiMiddleware = [EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'];

        Route::get('/sanctum/api/user', function (RequestInterface $request) {
            $authFactory = $this->app->get(AuthFactory::class);
            $user = $authFactory->guard('sanctum')->user();
            
            if (! $user) {
                abort(401);
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
    }

    public function testCanAuthorizeValidUserUsingAuthorizationHeader(): void
    {
        // Create a user with a token
        $user = new User();
        $user->id = 1;
        $user->email = 'test@example.com';
        
        // Create token in database
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test Token',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        // Mock the user provider to return our user
        $this->mockUserProvider($user);
        
        $response = $this->getJson('/sanctum/api/user', [
            'Authorization' => 'Bearer ' . $token->id . '|test',
        ]);
        
        $response->assertOk()
            ->assertJson(['email' => $user->email]);
    }

    /**
     * @dataProvider sanctumGuardsDataProvider
     */
    public function testCanAuthorizeValidUserUsingSanctumActingAs(?string $guard): void
    {
        $user = new User();
        $user->id = 1;
        $user->email = 'test@example.com';
        
        // Create token in database
        PersonalAccessToken::forceCreate([
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => 'Test Token',
            'token' => hash('sha256', 'test'),
            'abilities' => ['*'],
        ]);
        
        $this->mockUserProvider($user);
        
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