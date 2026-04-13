<?php

declare(strict_types=1);

namespace Hypervel\Routing\Matching;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;

class PortValidator implements ValidatorInterface
{
    /**
     * Validate a given rule against a route and request.
     */
    public function matches(Route $route, Request $request): bool
    {
        $port = $route->getPort();

        if (is_null($port)) {
            return true;
        }

        return $port === (int) $request->getPort();
    }
}
