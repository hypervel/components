<?php

declare(strict_types=1);

namespace RequestUser;

use Hypervel\Http\Request;

use function PHPStan\Testing\assertType;

function test(Request $request): void
{
    assertType('App\Admin|App\User|null', $request->user());
    assertType('App\User|null', $request->user('web'));
    assertType('App\Admin|null', $request->user('admin'));
    // An empty guard name is the default guard, which may be any configured guard.
    assertType('App\Admin|App\User|null', $request->user(''));
}
