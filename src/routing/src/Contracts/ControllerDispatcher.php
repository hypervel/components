<?php

declare(strict_types=1);

namespace Hypervel\Routing\Contracts;

use Hypervel\Routing\Route;

interface ControllerDispatcher
{
    /**
     * Dispatch a request to a given controller and method.
     */
    public function dispatch(Route $route, mixed $controller, string $method): mixed;

    /**
     * Get the middleware for the controller instance.
     */
    public function getMiddleware(mixed $controller, string $method): array;
}
