<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Traits;

use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Dispatcher\ParsedMiddleware;
use Hypervel\Router\Exceptions\InvalidMiddlewareExclusionException;
use Hypervel\Router\MiddlewareExclusionManager;
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

        $cacheKey = $dispatchFound
            ? "{$this->serverName}_{$dispatched->handler->route}_{$request->getMethod()}"
            : 'none';

        if (! is_null($cache = $this->cachedMiddleware[$cacheKey] ?? null)) {
            return $cache;
        }

        // Fetch registered and excluded middleware only on cache miss
        $registeredMiddleware = $dispatchFound
            ? MiddlewareManager::get($this->serverName, $dispatched->handler->route, $request->getMethod())
            : [];

        $excludedMiddleware = $dispatchFound
            ? MiddlewareExclusionManager::get($this->serverName, $dispatched->handler->route, $request->getMethod())
            : [];

        $middleware = $this->resolveMiddleware(
            array_merge($this->middleware, $registeredMiddleware),
            $excludedMiddleware
        );

        if ($middleware && $this->middlewarePriority) {
            $middleware = $this->sortMiddleware($middleware);
        }

        return $this->cachedMiddleware[$cacheKey] = $middleware;
    }

    /**
     * Resolve middleware, expanding groups and aliases, then applying exclusions.
     *
     * @param array $middlewares The middleware to resolve
     * @param array $excluded The middleware to exclude (applied after group expansion)
     * @return ParsedMiddleware[]
     */
    protected function resolveMiddleware(array $middlewares, array $excluded = []): array
    {
        $resolved = [];
        foreach ($middlewares as $middleware) {
            $parsedMiddleware = $this->parseMiddleware($middleware);
            $name = $parsedMiddleware->getName();
            $signature = $parsedMiddleware->getSignature();
            if (isset($this->middlewareAliases[$name])) {
                $resolved[$signature] = $this->parseMiddleware(
                    $this->middlewareAliases[$name],
                    $parsedMiddleware->getParameters()
                );
                continue;
            }
            if (isset($this->middlewareGroups[$name])) {
                foreach ($this->middlewareGroups[$name] as $groupMiddleware) {
                    $parsedMiddleware = $this->parseMiddleware($groupMiddleware);
                    if (isset($this->middlewareAliases[$name = $parsedMiddleware->getName()])) {
                        $parsedMiddleware = $this->parseMiddleware(
                            $this->middlewareAliases[$name],
                            $parsedMiddleware->getParameters()
                        );
                    }
                    $resolved[$parsedMiddleware->getSignature()] = $parsedMiddleware;
                }
                continue;
            }
            $resolved[$signature] = $parsedMiddleware;
        }

        // Apply exclusions after group expansion
        if ($excluded) {
            $expandedExcluded = $this->expandExcludedMiddleware($excluded);
            $resolved = array_filter(
                $resolved,
                fn (ParsedMiddleware $m) => ! in_array($m->getName(), $expandedExcluded, true)
            );
        }

        return array_values($resolved);
    }

    /**
     * Expand excluded middleware, resolving aliases and groups to class names.
     *
     * @param array $excluded The middleware to exclude
     * @return string[] The expanded list of middleware class names to exclude
     * @throws InvalidMiddlewareExclusionException If exclusion contains parameters
     */
    protected function expandExcludedMiddleware(array $excluded): array
    {
        $expanded = [];

        foreach ($excluded as $middleware) {
            // Parameters don't belong in exclusions - you're excluding the middleware entirely
            if (str_contains($middleware, ':')) {
                throw new InvalidMiddlewareExclusionException($middleware);
            }

            // Check if it's an alias
            if (isset($this->middlewareAliases[$middleware])) {
                $expanded[] = $this->middlewareAliases[$middleware];
                continue;
            }

            // Check if it's a group - expand all middleware in the group
            if (isset($this->middlewareGroups[$middleware])) {
                foreach ($this->middlewareGroups[$middleware] as $groupMiddleware) {
                    // Resolve alias within group
                    if (isset($this->middlewareAliases[$groupMiddleware])) {
                        $expanded[] = $this->middlewareAliases[$groupMiddleware];
                    } else {
                        $expanded[] = $groupMiddleware;
                    }
                }
                continue;
            }

            // It's a class name
            $expanded[] = $middleware;
        }

        return $expanded;
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

    public function parseMiddleware(string $middleware, array $parameters = []): ParsedMiddleware
    {
        // It's only for passing parameters in alias or group.
        if ($parameters) {
            $middleware .= ':' . implode(',', $parameters);
        }

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

        return $this;
    }

    /**
     * Add the given middleware to the middleware priority list before other middleware.
     *
     * @param array<int, string>|string $before
     */
    public function addToMiddlewarePriorityBefore(string|array $before, string $middleware): static
    {
        return $this->addToMiddlewarePriorityRelative($before, $middleware, after: false);
    }

    /**
     * Add the given middleware to the middleware priority list after other middleware.
     *
     * @param array<int, string>|string $after
     */
    public function addToMiddlewarePriorityAfter(string|array $after, string $middleware): static
    {
        return $this->addToMiddlewarePriorityRelative($after, $middleware, after: true);
    }

    /**
     * Add the given middleware to the middleware priority list relative to other middleware.
     *
     * @param array<int, string>|string $existing
     */
    protected function addToMiddlewarePriorityRelative(string|array $existing, string $middleware, bool $after = true): static
    {
        if (! in_array($middleware, $this->middlewarePriority)) {
            $index = $after ? 0 : count($this->middlewarePriority);

            foreach ((array) $existing as $existingMiddleware) {
                if (in_array($existingMiddleware, $this->middlewarePriority)) {
                    $middlewareIndex = array_search($existingMiddleware, $this->middlewarePriority);

                    if ($after && $middlewareIndex > $index) {
                        $index = $middlewareIndex + 1;
                    } elseif ($after === false && $middlewareIndex < $index) {
                        $index = $middlewareIndex;
                    }
                }
            }

            if ($index === 0 && $after === false) {
                array_unshift($this->middlewarePriority, $middleware);
            } elseif (($after && $index === 0) || $index === count($this->middlewarePriority)) {
                $this->middlewarePriority[] = $middleware;
            } else {
                array_splice($this->middlewarePriority, $index, 0, $middleware);
            }
        }

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

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

        $this->cachedMiddleware = [];

        return $this;
    }

    /**
     * Set the application's middleware priority.
     */
    public function setMiddlewarePriority(array $priority): static
    {
        $this->middlewarePriority = $priority;

        $this->cachedMiddleware = [];

        return $this;
    }
}
