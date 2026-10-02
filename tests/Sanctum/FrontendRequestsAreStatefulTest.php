<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;

class FrontendRequestsAreStatefulTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Get the migration options.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => [
                __DIR__ . '/../../src/sanctum/database/migrations',
                __DIR__ . '/Fixtures/migrations',
            ],
        ];
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'auth.guards.sanctum.provider' => 'users',
            'auth.providers.users.model' => User::class,
            'sanctum.middleware.encrypt_cookies' => EncryptCookies::class,
            'sanctum.middleware.validate_csrf_token' => PreventRequestForgery::class,
        ]);
    }

    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $webMiddleware = ['web', 'auth.session'];
        $apiMiddleware = [EnsureFrontendRequestsAreStateful::class, 'api', 'auth:sanctum'];

        $router->get('/sanctum/api/user', function (Request $request): string {
            abort_if(is_null($request->user()), 401);

            return $request->user()->email;
        })->middleware($apiMiddleware);

        $router->post('/sanctum/api/password', function (Request $request): string {
            abort_if(is_null($request->user()), 401);

            $request->user()->update(['password' => bcrypt('hypervel')]);

            return $request->user()->email;
        })->middleware($apiMiddleware);

        $router->get('/sanctum/web/user', function (Request $request): string {
            abort_if(is_null($request->user()), 401);

            return $request->user()->email;
        })->middleware($apiMiddleware);

        $router->get('web/user', function (Request $request): string {
            abort_if(is_null($request->user()), 401);

            return $request->user()->email;
        })->middleware($webMiddleware);

        $router->get('/sanctum/api/logout', function (): string {
            auth()->guard('web')->logout();
            session()->flush();

            return 'logged out';
        })->middleware($apiMiddleware);
    }

    public function testMiddlewareKeepsSessionLoggedInWhenSanctumRequestChangesPassword(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/web/user', [
                'origin' => config('app.url'),
            ])
            ->assertOk()
            ->assertSee($user->email);

        $this->getJson('/sanctum/api/user', [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email);

        $this->postJson('/sanctum/api/password', [], [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email);

        $this->getJson('/sanctum/api/user', [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email);
    }

    #[DataProvider('sanctumGuardsDataProvider')]
    public function testMiddlewareCanDeauthorizeValidUserUsingActingAsAfterPasswordChangeFromSanctumGuard(?string $guard): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, [], $guard);

        $this->getJson('/web/user', [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email);

        $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email);

        $user->password = bcrypt('hypervel');
        $user->save();

        $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ])->assertStatus(401);
    }

    /**
     * Get the guards passed to Sanctum's actingAs helper.
     */
    public static function sanctumGuardsDataProvider(): iterable
    {
        yield [null];
        yield ['web'];
    }

    public function testMiddlewareCanDeauthorizeValidUserUsingActingAsAfterPasswordChangeComingFromWebGuard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/web/user', [
                'origin' => config('app.url'),
            ])
            ->assertOk()
            ->assertSee($user->email);

        $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email);

        $user->password = bcrypt('hypervel');
        $user->save();

        $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ])->assertStatus(401);
    }

    public function testMiddlewareRemovesPasswordHashAfterSessionIsClearedDuringRequest(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/web/user', [
                'origin' => config('app.url'),
            ])
            ->assertOk()
            ->assertSee($user->email);

        $this->getJson('/sanctum/web/user', [
            'origin' => config('app.url'),
        ])
            ->assertOk()
            ->assertSee($user->email)->assertSessionHas('password_hash_web');

        $this->getJson('/sanctum/api/logout', [
            'origin' => config('app.url'),
        ])->assertOk()->assertSee('logged out')->assertSessionMissing('password_hash_web');
    }
}
