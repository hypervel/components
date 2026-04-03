<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing\Routing;

use Hypervel\Routing\Contracts\CallableDispatcher;
use Hypervel\Routing\Route;

class TracingCallableDispatcherTracing extends TracingRoutingDispatcher implements CallableDispatcher
{
    /**
     * Create a new tracing callable dispatcher instance.
     */
    public function __construct(
        private readonly CallableDispatcher $dispatcher,
    ) {
    }

    /**
     * Dispatch a request to a given callable.
     */
    public function dispatch(Route $route, callable $callable): mixed
    {
        return $this->wrapRouteDispatch(function () use ($route, $callable) {
            return $this->dispatcher->dispatch($route, $callable);
        }, $route);
    }
}
