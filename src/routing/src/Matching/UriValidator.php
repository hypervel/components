<?php

declare(strict_types=1);

namespace Hypervel\Routing\Matching;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;

class UriValidator implements ValidatorInterface
{
    /**
     * Validate a given rule against a route and request.
     */
    public function matches(Route $route, Request $request): bool
    {
        $path = rtrim($request->getPathInfo(), '/') ?: '/';

        return (bool) preg_match($route->getCompiled()->getRegex(), rawurldecode($path));
    }
}
