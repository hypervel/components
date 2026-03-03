<?php

declare(strict_types=1);

namespace Hypervel\Routing\Contracts;

use Hypervel\Routing\Route;

interface CallableDispatcher
{
    /**
     * Dispatch a request to a given callable.
     */
    public function dispatch(Route $route, callable $callable): mixed;
}
