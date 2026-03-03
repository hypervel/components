<?php

declare(strict_types=1);

namespace Hypervel\Routing\Matching;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;

class MethodValidator implements ValidatorInterface
{
    /**
     * Validate a given rule against a route and request.
     */
    public function matches(Route $route, Request $request): bool
    {
        return in_array($request->getMethod(), $route->methods(), true);
    }
}
