<?php

declare(strict_types=1);

namespace Route;

use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Route as RouteFacade;

use function PHPStan\Testing\assertType;

function test(Route $route): void
{
    assertType('Hypervel\Routing\Route', RouteFacade::get('/awesome', 'foobar')->middleware('foobar'));
    assertType('Hypervel\Routing\Route', $route->middleware('foobar'));
    // Hypervel: route middleware may include closures, which Router::gatherRouteMiddleware() runs.
    assertType('array<Closure|string>', $route->middleware());
}
