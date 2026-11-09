<?php

declare(strict_types=1);

namespace AuthGuards;

use Hypervel\Auth\GenericUser;
use Hypervel\Auth\RequestGuard;
use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Support\Facades\Auth;

use function PHPStan\Testing\assertType;

enum NumberedGuard: int
{
    case Zero = 0;
}

/**
 * @param list<string> $names
 * @param non-empty-list<string> $someNames
 */
function test(string $name, ?string $maybeName, array $names, array $someNames): void
{
    // shouldUse() changes the default guard per coroutine, so it may be any configured guard.
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', Auth::guard());
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', Auth::guard(null));
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', Auth::guard(''));
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', auth()->guard());
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', auth(''));

    // Resolving a guard that is not configured throws, so an unknown name may be any configured guard.
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', Auth::guard($name));
    assertType('Hypervel\Auth\RequestGuard|Hypervel\Auth\SessionGuard', Auth::guard(...$names));

    // auth() returns the factory for a null or omitted guard, so these keep the declaration.
    assertType('Hypervel\Contracts\Auth\Factory|Hypervel\Contracts\Auth\Guard', auth($maybeName));

    // An empty unpacked list leaves the guard null, so auth() may return the factory.
    assertType('Hypervel\Auth\AuthManager|Hypervel\Contracts\Auth\Guard', auth(...$names));
    assertType('Hypervel\Contracts\Auth\Guard', auth(...$someNames));

    assertType(SessionGuard::class, Auth::guard('0'));
    assertType(SessionGuard::class, Auth::guard(NumberedGuard::Zero));
    assertType('App\User|null', Auth::guard(NumberedGuard::Zero)->user());

    // A custom creator wins over the manager's own createTokenDriver().
    assertType(RequestGuard::class, Auth::guard('api'));
    assertType(RequestGuard::class, Auth::guard('members'));

    // An Eloquent provider gives its model to any guard driver.
    assertType('App\User|null', Auth::guard('web')->user());
    assertType('App\User|null', Auth::guard('members')->user());
    // The stock session and token guards give a database provider's GenericUser.
    assertType(GenericUser::class . '|null', Auth::guard('legacy')->user());

    // Other guards and providers keep the declared user type.
    assertType(Authenticatable::class . '|null', Auth::guard('api')->user());
    assertType(Authenticatable::class . '|null', Auth::guard('request')->user());
    assertType(Authenticatable::class . '|null', Auth::guard('remote')->user());
    assertType(Authenticatable::class . '|null', Auth::guard('missing')->user());
    assertType(Authenticatable::class . '|null', Auth::guard('providerless')->user());
    assertType(Authenticatable::class . '|null', Auth::guard()->user());
    assertType(Authenticatable::class . '|null', Auth::user());

    // A guard macro is only callable through AuthManager when every configured guard registers it.
    assertType('int', Auth::guardMacro());
    assertType('int', auth()->guardMacro());
    assertType('*ERROR*', Auth::sessionGuardMacro());
    assertType('*ERROR*', auth()->sessionGuardMacro());
}
