<?php

namespace Auth;

use App\Admin;
use App\User;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\SessionGuard;
use Hypervel\Auth\TokenGuard;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\Guard;
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
): void {
    assertType('App\Admin|App\User|null', Auth::user());
    assertType('bool', Auth::check());
    assertType('int|string|null', Auth::id());
    // Upstream types the default guard from auth.defaults.guard. shouldUse() changes the
    // default per coroutine, so Hypervel types it as any configured guard, and this app
    // has no passport driver, so the default keeps the declared Guard without logout().
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

    // A guard name may be an enum case, as AuthManager::guard() reads it with enum_value().
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
    assertType(TokenGuard::class, auth()->guard('api'));
    assertType('App\Admin|null', auth()->guard(GuardName::Admin)->user());

    // A known set of names gives each named guard.
    assertType('Hypervel\Auth\SessionGuard|Hypervel\Auth\TokenGuard', Auth::guard($adminOrApi));
    assertType('App\Admin|App\User|null', Auth::guard($adminOrApi)->user());

    // Narrower or explicit declarations keep their types.
    assertType(SessionGuard::class, $sessionGuardFactory->guard('api'));
    assertType('App\Admin|null', $adminGuard->user());
    assertType('App\User|null', $userAuthManager->user());
    assertType('App\User|null', UserAuth::user());
}
