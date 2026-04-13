<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Tracing\Routing;

use Hypervel\Routing\Contracts\ControllerDispatcher;
use Hypervel\Routing\Route;

class TracingControllerDispatcherTracing extends TracingRoutingDispatcher implements ControllerDispatcher
{
    /**
     * Create a new tracing controller dispatcher instance.
     */
    public function __construct(
        private readonly ControllerDispatcher $dispatcher,
    ) {
    }

    /**
     * Dispatch a request to a given controller and method.
     */
    public function dispatch(Route $route, mixed $controller, string $method): mixed
    {
        return $this->wrapRouteDispatch(function () use ($route, $controller, $method) {
            return $this->dispatcher->dispatch($route, $controller, $method);
        }, $route);
    }

    /**
     * Get the middleware for the controller instance.
     */
    public function getMiddleware(mixed $controller, string $method): array
    {
        return $this->dispatcher->getMiddleware($controller, $method);
    }
}
