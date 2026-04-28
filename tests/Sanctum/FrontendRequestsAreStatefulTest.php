<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\User;

class FrontendRequestsAreStatefulTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => [
                __DIR__ . '/../../src/sanctum/database/migrations',
                __DIR__ . '/migrations',
            ],
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set([
            'auth.guards.sanctum.driver' => 'sanctum',
            'auth.guards.sanctum.provider' => 'users',
            'auth.providers.users.model' => User::class,
            'sanctum.middleware' => [
                StartSession::class,
                PreventRequestForgery::class,
            ],
        ]);

        $this->app->make(SanctumServiceProvider::class)->register();
        $this->app->make(SanctumServiceProvider::class)->boot();

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        $router = $this->app->make(Router::class);

        $webMiddleware = [
            StartSession::class,
            PreventRequestForgery::class,
            Authenticate::class . ':web',
        ];
        $apiMiddleware = [
            EnsureFrontendRequestsAreStateful::class,
            Authenticate::class . ':sanctum',
        ];

        $router->get('/sanctum/api/user', function (Request $request) {
            abort_if(is_null($request->user()), 401);

            return $request->user()->email;
        }, ['middleware' => $apiMiddleware]);

        $router->post('/sanctum/api/password', function (Request $request) {
            abort_if(is_null($request->user()), 401);

            $request->user()->update(['password' => bcrypt('laravel')]);

            return $request->user()->email;
        }, ['middleware' => $apiMiddleware]);

        $router->get('/sanctum/web/user', function (Request $request) {
            abort_if(is_null($request->user()), 401);

            return $request->user()->email;
        }, ['middleware' => $apiMiddleware]);

        $router->get('web/user', function (Request $request) {
            abort_if(is_null($request->user()), 401);

            return $request->user()->email;
        }, ['middleware' => $webMiddleware]);

        $router->get('/sanctum/api/logout', function () {
            auth()->guard('web')->logout();
            session()->flush();

            return 'logged out';
        }, ['middleware' => $apiMiddleware]);
    }

    public function testMiddlewareKeepsSessionLoggedInWhenSanctumRequestChangesPassword()
    {
        $user = $this->createUser();

        $this->actingAs($user, 'web');

        $this->getJson('/web/user', [
            'origin' => config('app.url'),
        ])->assertOk()
            ->assertSee($user->email);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/sanctum/api/user', [
            'origin' => config('app.url'),
        ])->assertOk()
            ->assertSee($user->email);

        $response = $this->get('/sanctum/csrf-cookie', [
            'origin' => config('app.url'),
        ])->assertNoContent();
        $cookies = Collection::make($response->headers->getCookies());

        $csrfToken = $cookies->where(function ($cookie) {
            return $cookie->getName() === 'XSRF-TOKEN';
        })->firstOrFail();
        $sessionCookie = $cookies->where(function ($cookie) {
            return $cookie->getName() === 'hypervel_session';
        })->firstOrFail();

        $this->withCookie('hypervel_session', $sessionCookie->getValue())
            ->postJson('/sanctum/api/password', [], [
                'origin' => config('app.url'),
                'X-CSRF-TOKEN' => $csrfToken->getValue(),
            ])->assertOk()
            ->assertSee($user->email);

        $this->getJson('/sanctum/api/user', [
            'origin' => config('app.url'),
        ])->assertOk()
            ->assertSee($user->email);
    }

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
