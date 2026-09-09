<?php

declare(strict_types=1);

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

use function PHPStan\Testing\assertType;

assertType('string', Auth::hashPasswordForCookie('password-hash'));
assertType('string', Auth::hashPasswordForCookie(null));
assertType(Authenticatable::class . '|null', Auth::logoutOtherDevices('password'));

// Guard reads and authentication attempts may observe different request state.
if (Auth::check()) {
    assertType('bool', Auth::check());
}

if (Auth::basic() === null) {
    assertType(Response::class . '|null', Auth::basic());
}

if (Auth::onceBasic() === null) {
    assertType(Response::class . '|null', Auth::onceBasic());
}
