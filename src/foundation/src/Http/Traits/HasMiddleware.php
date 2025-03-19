<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Traits;

use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Dispatcher\ParsedMiddleware;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

trait HasMiddleware
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected array $middleware = [];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected array $middlewareGroups = [];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected array $middlewareAliases = [];

    /**
     * The priority-sorted list of middleware.
     *
     * Forces non-global middleware to always be in the given order.
     *
     * @var string[]
     */
    protected array $middlewarePriority = [];

    /**
     * Cached parsedMiddleware.
     *
     * @var ParsedMiddleware[]
     */
    protected array $parsedMiddleware = [];

    /**
     * Cached middleware.
     */
    protected array $cachedMiddleware = [];

    /**
     * Get middleware array for request.
     */
    public function getMiddlewareForRequest(ServerRequestInterface $request): array
    {
        $dispatched = $request->getAttribute(Dispatched::class);

        $dispatchFound = $dispatched->isFound();
        $registeredMiddleware = $dispatchFound
            ? MiddlewareManager::get($this->serverName, $dispatched->handler->route, $request->getMethod())
            : [];

        $cacheKey = $dispatchFound
            ? "{$this->serverName}_{$dispatched->handler->route}_{$request->getMethod()}"
            : 'none';

        if (! is_null($cache = $this->cachedMiddleware[$cacheKey] ?? null)) {
            return $cache;
        }

        $middleware = $this->resolveMiddleware(
            array_merge($this->middleware, $registeredMiddleware)
        );

        if ($middleware && $this->middlewarePriority) {
            $middleware = $this->sortMiddleware($middleware);
        }

        return $this->cachedMiddleware[$cacheKey] = $middleware;
    }

    protected function resolveMiddleware(array $middlewares): array
    {
        $resolved = [];
        foreach ($middlewares as $middleware) {
            $parsedMiddleware = $this->parseMiddleware($middleware);
            $name = $parsedMiddleware->getName();
            $signature = $parsedMiddleware->getSignature();
            if (isset($this->middlewareAliases[$name])) {
                $resolved[$signature] = $this->parseMiddleware($this->middlewareAliases[$name]);
                continue;
            }
            if (isset($this->middlewareGroups[$name])) {
                foreach ($this->middlewareGroups[$name] as $groupMiddleware) {
                    $parsedMiddleware = $this->parseMiddleware($groupMiddleware);
                    if (isset($this->middlewareAliases[$name = $parsedMiddleware->getName()])) {
                        $parsedMiddleware = $this->parseMiddleware(
                            $this->middlewareAliases[$name] . ':' . implode(',', $parsedMiddleware->getParameters())
                        );
                    }
                    $resolved[$parsedMiddleware->getSignature()] = $parsedMiddleware;
                }
                continue;
            }
            $resolved[$signature] = $parsedMiddleware;
        }

        return array_values($resolved);
    }

    protected function sortMiddleware(array $middlewares): array
    {
        $lastIndex = 0;
        foreach ($middlewares as $index => $middleware) {
            if (! is_null($priorityIndex = $this->priorityMapIndex($middleware->getName()))) {
                // This middleware is in the priority map. If we have encountered another middleware
                // that was also in the priority map and was at a lower priority than the current
                // middleware, we will move this middleware to be above the previous encounter.
                if (isset($lastPriorityIndex) && $priorityIndex < $lastPriorityIndex) {
                    return $this->sortMiddleware(
                        array_values($this->moveMiddleware($middlewares, $index, $lastIndex))
                    );
                }

                // This middleware is in the priority map; but, this is the first middleware we have
                // encountered from the map thus far. We'll save its current index plus its index
                // from the priority map so we can compare against them on the next iterations.
                $lastIndex = $index;

                $lastPriorityIndex = $priorityIndex;
            }
        }

        return $middlewares;
    }

    /**
     * Calculate the priority map index of the middleware.
     */
    protected function priorityMapIndex(string $middleware): ?int
    {
        $priorityIndex = array_search($middleware, $this->middlewarePriority);

        if ($priorityIndex !== false) {
            return $priorityIndex;
        }

        return null;
    }

    /**
     * Splice a middleware into a new position and remove the old entry.
     */
    protected function moveMiddleware(array $middlewares, int $from, int $to): array
    {
        array_splice($middlewares, $to, 0, [$middlewares[$from]]);
        unset($middlewares[$from + 1]);

        return $middlewares;
    }

    public function parseMiddleware(string $middleware): ParsedMiddleware
    {
        if ($parsedMiddleware = $this->parsedMiddleware[$middleware] ?? null) {
            return $parsedMiddleware;
        }

        return $this->parsedMiddleware[$middleware] = new ParsedMiddleware($middleware);
    }

    /**
     * Determine if the kernel has a given middleware.
     */
    public function hasMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->middleware);
    }

    /**
     * Add a new middleware to the beginning of the stack if it does not already exist.
     */
    public function prependMiddleware(string $middleware): static
    {
        if (array_search($middleware, $this->middleware) === false) {
            array_unshift($this->middleware, $middleware);
        }

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Add a new middleware to end of the stack if it does not already exist.
     */
    public function pushMiddleware(string $middleware): static
    {
        if (array_search($middleware, $this->middleware) === false) {
            $this->middleware[] = $middleware;
        }

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Prepend the given middleware to the given middleware group.
     *
     * @throws InvalidArgumentException
     */
    public function prependMiddlewareToGroup(string $group, string $middleware): static
    {
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            array_unshift($this->middlewareGroups[$group], $middleware);
        }

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Append the given middleware to the given middleware group.
     *
     * @throws InvalidArgumentException
     */
    public function appendMiddlewareToGroup(string $group, string $middleware): static
    {
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            $this->middlewareGroups[$group][] = $middleware;
        }

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Prepend the given middleware to the middleware priority list.
     */
    public function prependToMiddlewarePriority(string $middleware): static
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            array_unshift($this->middlewarePriority, $middleware);
        }

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Append the given middleware to the middleware priority list.
     */
    public function appendToMiddlewarePriority(string $middleware): static
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            $this->middlewarePriority[] = $middleware;
        }

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Get the priority-sorted list of middleware.
     */
    public function getMiddlewarePriority(): array
    {
        return $this->middlewarePriority;
    }

    /**
     * Get the application's global middleware.
     */
    public function getGlobalMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Set the application's global middleware.
     */
    public function setGlobalMiddleware(array $middleware): static
    {
        $this->middleware = $middleware;

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Get the application's route middleware groups.
     */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }

    /**
     * Set the application's middleware groups.
     */
    public function setMiddlewareGroups(array $groups): static
    {
        $this->middlewareGroups = $groups;

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Add the application's middleware groups.
     */
    public function addMiddlewareGroup(string $group, array $middleware): static
    {
        if (isset($this->middlewareGroups[$group])) {
            $middleware = array_merge($this->middlewareGroups[$group], $middleware);
        }

        $this->middlewareGroups[$group] = $middleware;

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Get the application's route middleware aliases.
     */
    public function getMiddlewareAliases(): array
    {
        return $this->middlewareAliases;
    }

    /**
     * Set the application's route middleware aliases.
     */
    public function setMiddlewareAliases(array $aliases): static
    {
        $this->middlewareAliases = $aliases;

        $this->cachedMiddleware[] = [];

        return $this;
    }

    /**
     * Set the application's middleware priority.
     */
    public function setMiddlewarePriority(array $priority): static
    {
        $this->middlewarePriority = $priority;

        $this->cachedMiddleware[] = [];

        return $this;
    }
}
