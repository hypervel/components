<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Testing\Concerns;

use Hypervel\Auth\AuthManager;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Auth\Authenticatable as UserContract;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Mockery as m;

#[WithMigration]
class InteractsWithAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
            'passwords' => null,
            'password_timeout' => null,
            'hash' => false,
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('is_active')->default(0);
            $table->string('api_token')->nullable();
        });

        User::forceCreate([
            'username' => 'taylorotwell',
            'email' => 'taylorotwell@hypervel.org',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    public function testAssertAsGuest()
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')
            ->twice()
            ->andReturn(false);

        $this->app->make('auth')
            ->extend('foo', fn () => $guard);
        $this->app->make('config')
            ->set('auth.guards.foo', [
                'driver' => 'foo',
                'provider' => 'users',
            ]);

        CoroutineContext::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, 'foo');

        $this->assertGuest();
        $this->assertFalse($this->isAuthenticated());
    }

    public function testAssertActingAs()
    {
        $user = m::mock(UserContract::class);
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('check')
            ->once()
            ->andReturn(true);
        $guard->shouldReceive('setUser')
            ->once()
            ->andReturnSelf();
        $guard->shouldReceive('user')
            ->once()
            ->andReturn($user);
        $user->shouldReceive('getAuthIdentifier')
            ->twice()
            ->andReturn('id');

        $this->app->make('auth')
            ->extend('foo', fn () => $guard);
        $this->app->make('config')
            ->set('auth.guards.foo', [
                'driver' => 'foo',
                'provider' => 'users',
            ]);

        CoroutineContext::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, 'foo');

        $this->actingAs($user);

        $this->assertTrue($this->isAuthenticated());
        $this->assertAuthenticatedAs($user);
    }

    public function testActingAsIsProperlyHandledForSessionAuth()
    {
        Route::get('me', function (Request $request) {
            return 'Hello ' . $request->user()->username;
        })->middleware(['auth']);

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->actingAs($user)
            ->get('/me')
            ->assertSuccessful()
            ->assertSeeText('Hello taylorotwell');
    }

    public function testRequestDrivenLogoutClearsParentSessionGuardAuthenticationContext(): void
    {
        Route::post('logout', function (Request $request) {
            Auth::guard()->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response('', 204);
        })->middleware(['web', 'auth']);

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->actingAs($user)
            ->post('/logout')
            ->assertStatus(204);

        $this->assertGuest();
    }

    public function testJsonRequestDrivenLogoutClearsParentSessionGuardAuthenticationContext(): void
    {
        Route::post('logout', function (Request $request) {
            Auth::guard()->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response('', 204);
        })->middleware(['web', 'auth']);

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->actingAs($user)
            ->postJson('/logout')
            ->assertStatus(204);

        $this->assertGuest();
    }

    public function testNonMutatingRequestKeepsParentSessionGuardAuthenticationContext(): void
    {
        Route::get('me', function (Request $request) {
            return 'Hello ' . $request->user()->username;
        })->middleware(['web', 'auth']);

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->actingAs($user)
            ->get('/me')
            ->assertSuccessful()
            ->assertSeeText('Hello taylorotwell');

        $this->assertAuthenticatedAs($user);
    }

    public function testRequestDrivenLoginSetsParentSessionGuardAuthenticationContext(): void
    {
        Route::post('login', function () {
            Auth::guard()->login(User::where('username', '=', 'taylorotwell')->first());

            return response('', 204);
        })->middleware(['web']);

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->assertGuest();

        $this->post('/login')
            ->assertStatus(204);

        $this->assertAuthenticatedAs($user);
    }

    public function testRequestDrivenLogoutOnlyClearsTheLoggedOutGuard(): void
    {
        $this->app->make('config')
            ->set('auth.guards.secondary', [
                'driver' => 'session',
                'provider' => 'users',
                'passwords' => null,
                'password_timeout' => null,
                'remember' => null,
            ]);

        Route::post('logout-web', function (Request $request) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response('', 204);
        })->middleware(['web']);

        $webUser = User::where('username', '=', 'taylorotwell')->first();
        $secondaryUser = User::forceCreate([
            'username' => 'secondary',
            'email' => 'secondary@hypervel.org',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->actingAs($webUser, 'web');
        $this->actingAs($secondaryUser, 'secondary');

        $this->post('/logout-web')
            ->assertStatus(204);

        $this->assertGuest('web');
        $this->assertAuthenticatedAs($secondaryUser, 'secondary');
    }

    public function testActingAsIsProperlyHandledForAuthViaRequest()
    {
        Route::get('me', function (Request $request) {
            return 'Hello ' . $request->user()->username;
        })->middleware(['auth:api']);

        Auth::viaRequest('api', function ($request) {
            return $request->user();
        });

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->actingAs($user, 'api')
            ->get('/me')
            ->assertSuccessful()
            ->assertSeeText('Hello taylorotwell');
    }

    public function testTokenGuardSynchronizesOnlyExplicitUsersAcrossRequests(): void
    {
        Route::get('api-user', static function () {
            return response()->json([
                'username' => Auth::guard('api')->user()?->username,
            ]);
        });
        Route::post('api-forget-user', static function () {
            Auth::guard('api')->forgetUser();

            return response()->noContent();
        });

        $firstUser = User::where('username', 'taylorotwell')->firstOrFail();
        $secondUser = User::forceCreate([
            'username' => 'second-user',
            'email' => 'second@hypervel.org',
            'password' => bcrypt('password'),
            'is_active' => true,
            'api_token' => null,
        ]);
        $firstUser->forceFill(['api_token' => 'shared-token'])->save();

        $this->withHeader('Authorization', 'Bearer shared-token')
            ->getJson('/api-user')
            ->assertOk()
            ->assertJson(['username' => 'taylorotwell']);

        $firstUser->forceFill(['api_token' => null])->save();
        $secondUser->forceFill(['api_token' => 'shared-token'])->save();

        $this->getJson('/api-user')
            ->assertOk()
            ->assertJson(['username' => 'second-user']);

        $this->actingAs($firstUser, 'api');

        $this->getJson('/api-user')
            ->assertOk()
            ->assertJson(['username' => 'taylorotwell']);

        $this->postJson('/api-forget-user')->assertNoContent();

        $this->getJson('/api-user')
            ->assertOk()
            ->assertJson(['username' => $secondUser->username]);
    }

    public function testRequestGuardSynchronizesOnlyExplicitUsersAcrossRequests(): void
    {
        $resolutions = 0;
        Auth::viaRequest('request-context', function (Request $request) use (&$resolutions) {
            ++$resolutions;

            return User::find($request->header('X-User-Id'));
        });
        config()->set('auth.guards.request-context', [
            'driver' => 'request-context',
            'provider' => 'users',
        ]);

        Route::get('request-user', static function () {
            return response()->json([
                'username' => Auth::guard('request-context')->user()?->username,
            ]);
        });
        Route::post('request-forget-user', static function () {
            Auth::guard('request-context')->forgetUser();

            return response()->noContent();
        });

        $firstUser = User::where('username', 'taylorotwell')->firstOrFail();
        $secondUser = User::forceCreate([
            'username' => 'second-user',
            'email' => 'second@hypervel.org',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->withHeader('X-User-Id', (string) $firstUser->getKey())
            ->getJson('/request-user')
            ->assertOk()
            ->assertJson(['username' => 'taylorotwell']);

        $this->withHeader('X-User-Id', (string) $secondUser->getKey())
            ->getJson('/request-user')
            ->assertOk()
            ->assertJson(['username' => 'second-user']);

        $this->assertSame(2, $resolutions);

        $this->actingAs($firstUser, 'request-context');

        $this->getJson('/request-user')
            ->assertOk()
            ->assertJson(['username' => 'taylorotwell']);

        $this->postJson('/request-forget-user')->assertNoContent();

        $this->getJson('/request-user')
            ->assertOk()
            ->assertJson(['username' => 'second-user']);
        $this->assertSame(3, $resolutions);
    }

    public function testActingAsGuestClearsTheUser()
    {
        Route::get('me', function (Request $request) {
            return 'Hello ' . $request->user()->username;
        })->middleware(['auth']);
        Route::get('login', function () {
            return 'Login';
        })->name('login');

        $user = User::where('username', '=', 'taylorotwell')->first();

        $this->actingAs($user);
        $this->assertAuthenticated();

        $this->get('/me')
            ->assertSuccessful()
            ->assertSeeText('Hello taylorotwell');

        $this->actingAsGuest();
        $this->assertGuest();

        $this->get('/me')
            ->assertRedirect(route('login'));
    }
}
