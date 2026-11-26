<?php

namespace MacrosRouteRegistrar;

use Hypervel\Routing\RouteRegistrar;

use function PHPStan\Testing\assertType;

/** RouteRegistrar uses Macroable but defines its own __call. */
function test(RouteRegistrar $routeRegistrar): void
{
    assertType('Hypervel\Routing\RouteRegistrar', $routeRegistrar->routeRegistrarMacro());
}
