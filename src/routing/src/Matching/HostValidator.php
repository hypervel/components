<?php

declare(strict_types=1);

namespace Hypervel\Routing\Matching;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;

class HostValidator implements ValidatorInterface
{
    /**
     * Validate a given rule against a route and request.
     */
    public function matches(Route $route, Request $request): bool
    {
        $hostRegex = $route->getCompiled()->getHostRegex();

        if (is_null($hostRegex)) {
            return true;
        }

        return (bool) preg_match($hostRegex, $request->getHost());
    }
}
