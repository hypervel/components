<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Routing;

use Hypervel\Routing\ControllerDispatcher;
use Hypervel\Routing\Route;
use RuntimeException;

class PrecognitionControllerDispatcher extends ControllerDispatcher
{
    /**
     * Dispatch a request to a given controller and method.
     */
    public function dispatch(Route $route, mixed $controller, string $method): mixed
    {
        $this->ensureMethodExists($controller, $method);

        $this->resolveParameters($route, $controller, $method);

        abort(204, headers: ['Precognition-Success' => 'true']);
    }

    /**
     * Ensure that the given method exists on the controller.
     *
     * @throws RuntimeException
     */
    protected function ensureMethodExists(object $controller, string $method): static
    {
        if (method_exists($controller, $method)) {
            return $this;
        }

        $class = $controller::class;

        throw new RuntimeException("Attempting to predict the outcome of the [{$class}::{$method}()] method but the method is not defined.");
    }
}
