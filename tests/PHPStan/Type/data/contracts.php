<?php

namespace Contracts;

use Hypervel\Contracts\Foundation\Application;

use function PHPStan\Testing\assertType;

function test(Application $app): void
{
    assertType('bool', $app->isLocal());
    // REMOVED: $session->ageFlashData(); contracts do not gain methods from their implementations.
}
