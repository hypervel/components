<?php

declare(strict_types=1);

namespace AuthUntypedGuard;

use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Support\Facades\Auth;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType(Guard::class, Auth::guard('untyped'));
    assertType(Guard::class, Auth::guard());
    assertType(SessionGuard::class, Auth::guard('web'));
}
