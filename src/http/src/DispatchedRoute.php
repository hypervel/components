<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Closure;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Router\RouteHandler;

class DispatchedRoute extends Dispatched
{
    /**
     * Get the route handler.
     */
    public function getHandler(): ?RouteHandler
    {
        /** @phpstan-ignore-next-line */
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
    public function getCallback(): array|Closure
    {
        return $this->getHandler()->getCallback();
    }

    /**
     * Get the route parameters.
     */
    public function getParameters(): array
    {
        return $this->params;
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
