<?php

declare(strict_types=1);

namespace Hypervel\Routing\Events;

use Hypervel\Http\Request;
use Hypervel\Routing\Route;

class RouteMatched
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Route $route,
        public readonly Request $request,
    ) {
    }
}
