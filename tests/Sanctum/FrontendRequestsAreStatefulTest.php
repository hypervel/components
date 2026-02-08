<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Auth\Middleware\Authenticate;
use Hypervel\Foundation\Http\Middleware\VerifyCsrfToken;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Router\Router;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Collection;
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

        $this->app->get(Repository::class)->set([
            'auth.guards.sanctum.driver' => 'sanctum',
            'auth.guards.sanctum.provider' => 'users',
            'auth.providers.users.model' => User::class,
            'sanctum.middleware' => [
                StartSession::class,
                VerifyCsrfToken::class,
            ],
        ]);

        $this->app->get(SanctumServiceProvider::class)->register();
        $this->app->get(SanctumServiceProvider::class)->boot();

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        $router = $this->app->get(Router::class);

        $webMiddleware = [
            StartSession::class,
            VerifyCsrfToken::class,
            Authenticate::class . ':session',
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

        $this->actingAs($user, 'session');

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
        $cookies = Collection::make($response->getCookies())
            ->flatten();

        $csrfToken = $cookies->where(function ($cookie) {
            return $cookie->getName() === 'XSRF-TOKEN';
        })->firstOrFail();
        $sessionCookie = $cookies->where(function ($cookie) {
            return $cookie->getName() === 'testing_session';
        })->firstOrFail();

        $this->withCookie('testing_session', $sessionCookie->getValue())
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
