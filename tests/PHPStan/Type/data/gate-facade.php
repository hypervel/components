<?php

namespace GateFacade;

use App\User;
use Hypervel\Support\Facades\Gate;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('Hypervel\Auth\Access\Gate', Gate::forUser(new User()));
    assertType('Hypervel\Auth\Access\Response', Gate::inspect('foo'));
    assertType('bool', Gate::has('foo'));
}
