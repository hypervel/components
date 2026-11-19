<?php

namespace Contracts;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Application as FoundationApplication;

use function PHPStan\Testing\assertType;

function test(Application $app, FoundationApplication $concrete): void
{
    assertType('bool', $app->isLocal());
    // REMOVED: $session->ageFlashData(); contracts do not gain methods from their implementations.

    // Hypervel: the application's path methods join a non-empty path, as the path helpers do.
    assertType('non-empty-string', $app->storagePath('logs'));
    assertType('non-empty-string', $concrete->storagePath('logs'));
}
