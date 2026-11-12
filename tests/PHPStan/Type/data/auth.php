<?php

namespace Auth;

use App\Admin;
use App\User;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\SessionGuard;
use Hypervel\Auth\TokenGuard;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Support\Facades\Auth;
use UnitEnum;

use function PHPStan\Testing\assertType;

enum GuardName: string
{
    case Admin = 'admin';
}

enum UnitGuardName
{
    case admin;
}

abstract class SessionGuardFactory implements Factory
{
    abstract public function guard(UnitEnum|string|null $name = null): SessionGuard;
}

abstract class AdminGuard implements Guard
{
    abstract public function user(): ?Admin;
}

class UserAuthManager extends AuthManager
{
    public function user(): ?User
    {
        return null;
    }
}

class ApiAuthManager extends AuthManager
{
    public function guard(UnitEnum|string|null $name = null): Guard|StatefulGuard
    {
        return parent::guard('api');
    }
}

class UserAuth extends Auth
{
    public static function user(): ?User
    {
        return null;
    }
}

/** @param 'admin'|'api' $adminOrApi */
function test(
    User $user,
    string $guard,
    ?string $maybeGuard,
    string $adminOrApi,
    SessionGuardFactory $sessionGuardFactory,
    AdminGuard $adminGuard,
    UserAuthManager $userAuthManager,
    ApiAuthManager $apiAuthManager,
): void {
    assertType('App\Admin|App\User|null', Auth::user());
    assertType('bool', Auth::check());
    assertType('int|string|null', Auth::id());
    // Upstream types the default from auth.defaults.guard, but shouldUse() changes it per coroutine,
    // and this app's untyped passport guard makes the union of every guard the declared Guard.
    assertType(Guard::class, Auth::guard());
    assertType('null', Auth::guard('web')->logout());
    assertType(SessionGuard::class, Auth::guard('web'));
    assertType(SessionGuard::class, Auth::guard('admin'));
    assertType(TokenGuard::class, Auth::guard('api'));
    // Upstream falls back to StatefulGuard, but only SessionGuard is stateful, so Hypervel
    // keeps the declared Guard for a guard it cannot type.
    assertType(Guard::class, Auth::guard('passport'));
    assertType(Guard::class, Auth::guard('undefined'));
    assertType(Guard::class, Auth::guard($guard));
    assertType(Guard::class, Auth::guard($maybeGuard));
    assertType('App\User|null', Auth::guard('web')->user());
    assertType('App\Admin|null', Auth::guard('admin')->user());
    assertType('null', Auth::guard('web')->login($user));

    assertType(SessionGuard::class, Auth::guard(GuardName::Admin));
    assertType('App\Admin|null', Auth::guard(GuardName::Admin)->user());
    assertType(SessionGuard::class, Auth::guard(UnitGuardName::admin));
    assertType('App\Admin|null', Auth::guard(name: UnitGuardName::admin)->user());

    assertType(AuthManager::class, auth());
    assertType(AuthManager::class, auth(null));
    assertType(AuthManager::class, auth(guard: null));
    assertType('App\Admin|App\User|null', auth()->user());
    assertType(TokenGuard::class, auth('api'));
    assertType(Guard::class, auth('passport'));
    assertType('App\Admin|null', auth('admin')->user());
    assertType('App\Admin|null', auth(guard: 'admin')->user());
    assertType('App\Admin|null', Auth('admin')->user());
    assertType(TokenGuard::class, auth()->guard('api'));
    assertType('App\Admin|null', auth()->guard(GuardName::Admin)->user());

    assertType('Hypervel\Auth\SessionGuard|Hypervel\Auth\TokenGuard', Auth::guard($adminOrApi));
    assertType('App\Admin|App\User|null', Auth::guard($adminOrApi)->user());

    assertType(SessionGuard::class, $sessionGuardFactory->guard('api'));
    assertType('App\Admin|null', $adminGuard->user());
    assertType('App\User|null', $userAuthManager->user());
    assertType('App\User|null', UserAuth::user());

    // A manager the container does not bind selects guards and users in its own way.
    assertType(Guard::class, $apiAuthManager->guard('web'));
    assertType('Hypervel\Contracts\Auth\Authenticatable|null', $apiAuthManager->guard('web')->user());
    assertType('Hypervel\Contracts\Auth\Authenticatable|null', $apiAuthManager->user());
}
