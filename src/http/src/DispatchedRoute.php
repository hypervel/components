<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Router\RouteHandler;

class DispatchedRoute extends Dispatched
{
    /**
     * Get the route handler.
     */
    public function getHandler(): ?RouteHandler
    {
        /* @phpstan-ignore-next-line */
        return $this->handler;
    }

    /**
     * Check if the route handler is a Closure.
     */
    public function isClosure(): bool
    {
        return $this->getHandler()->isClosure();
    }

    /**
     * Check whether the route's action is a controller.
     */
    public function isControllerAction(): bool
    {
        return $this->getHandler()->isControllerAction();
    }

    /**
     * Get the callback for the route handler.
     */
    public function getCallback(): array|callable|string
    {
        return $this->getHandler()->getCallback();
    }

    /**
     * Get the route parameters.
     */
    public function parameters(): array
    {
        return $this->params;
    }

    /**
     * Get a specific route parameter.
     */
    public function parameter(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Determine if the route has parameters.
     */
    public function hasParameters(): bool
    {
        return (bool) count($this->params);
    }

    /**
     * Determine a given parameter exists from the route.
     */
    public function hasParameter(string $name): bool
    {
        return array_key_exists($name, $this->params);
    }

    /**
     * Get the route name.
     */
    public function getName(): ?string
    {
        return $this->getHandler()->getName();
    }

    /**
     * Get the route middleware.
     */
    public function getMiddleware(): array
    {
        return $this->getHandler()->getMiddleware();
    }

    /**
     * Get the controller class used for the route.
     */
    public function getControllerClass(): ?string
    {
        return $this->getHandler()->getControllerClass();
    }

    /**
     * Get the parsed controller callback.
     */
    public function getControllerCallback(): array
    {
        return $this->getHandler()->getControllerCallback();
    }
}
