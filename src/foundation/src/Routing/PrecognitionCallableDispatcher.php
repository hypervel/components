<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Routing;

use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Route;

class PrecognitionCallableDispatcher extends CallableDispatcher
{
    /**
     * Dispatch a request to a given callable.
     */
    public function dispatch(Route $route, callable $callable): mixed
    {
        $this->resolveParameters($route, $callable);

        abort(204, headers: ['Precognition-Success' => 'true']);
    }
}
