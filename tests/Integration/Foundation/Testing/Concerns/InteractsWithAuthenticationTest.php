<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Testing\Concerns;

use Hypervel\Auth\AuthManager;
use Hypervel\Context\Context;
use Hypervel\Contracts\Auth\Authenticatable as UserContract;
use Hypervel\Contracts\Auth\Guard;
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

/**
 * @internal
 * @coversNothing
 */
#[WithMigration]
class InteractsWithAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.guards.api', [
            'driver' => 'token',
            'provider' => 'users',
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

        Context::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, 'foo');

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

        Context::set(AuthManager::DEFAULT_GUARD_CONTEXT_KEY, 'foo');

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
