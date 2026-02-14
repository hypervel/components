<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Closure;
use Hypervel\HttpServer\Router\Handler;
use RuntimeException;

class RouteHandler extends Handler
{
    /**
     * The parsed controller callback.
     */
    protected ?array $parsedControllerCallback = null;

    /**
     * Get the callback for the route handler.
     */
    public function getCallback(): array|callable|string
    {
        return $this->callback;
    }

    /**
     * Check if the route handler is a Closure.
     */
    public function isClosure(): bool
    {
        return $this->callback instanceof Closure;
    }

    /**
     * Check whether the route's action is a controller.
     */
    public function isControllerAction(): bool
    {
        return ! $this->isClosure();
    }

    /**
     * Get the route path.
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * Get the route options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get the route name.
     */
    public function getName(): ?string
    {
        return $this->options['as'] ?? null;
    }

    /**
     * Get the route middleware.
     */
    public function getMiddleware(): array
    {
        return $this->options['middleware'] ?? [];
    }

    /**
     * Get the controller class used for the route.
     */
    public function getControllerClass(): ?string
    {
        return $this->isControllerAction()
            ? $this->getControllerCallback()[0]
            : null;
    }

    /**
     * Get the parsed controller callback.
     */
    public function getControllerCallback(): array
    {
        if (! is_null($this->parsedControllerCallback)) {
            return $this->parsedControllerCallback;
        }

        return $this->parsedControllerCallback = $this->parseControllerCallback();
    }

    /**
     * Parse the controller.
     */
    protected function parseControllerCallback(): array
    {
        if (is_string($this->callback)) {
            if (str_contains($this->callback, '@')) {
                return explode('@', $this->callback);
            }
            if (str_contains($this->callback, '::')) {
                return explode('::', $this->callback);
            }
            return [$this->callback, '__invoke'];
        }
        if (is_array($this->callback) && isset($this->callback[0], $this->callback[1])) {
            return $this->callback;
        }

        throw new RuntimeException("Route handler doesn't exist.");
    }
}
