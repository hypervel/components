<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use LogicException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\RequestContext;

class CompiledRouteCollection extends AbstractRouteCollection
{
    /**
     * The compiled routes collection.
     */
    protected array $compiled = [];

    /**
     * An array of the route attributes keyed by name.
     */
    protected array $attributes = [];

    /**
     * The dynamically added routes that were added after loading the cached, compiled routes.
     */
    protected ?RouteCollection $routes = null;

    /**
     * The router instance used by the route.
     */
    protected Router $router;

    /**
     * The container instance used by the route.
     */
    protected Container $container;

    /**
     * Pre-built Route objects keyed by name for this collection.
     *
     * @var array<string, Route>
     */
    protected array $nameCache = [];

    /**
     * A cache of route names grouped by the HTTP method they respond to, built from the route attributes.
     *
     * @var null|array<string, array<int, string>>
     */
    protected ?array $routeNamesByMethod = null;

    /**
     * A cache of route names keyed by their controller action, built from the route attributes.
     *
     * @var null|array<string, string>
     */
    protected ?array $routeNameByAction = null;

    /**
     * Port lookup map for compiled routes, keyed by "METHOD domain+uri".
     *
     * Built lazily on first add() call. Used to detect port conflicts
     * between compiled and dynamically-added routes.
     *
     * @var null|array<string, null|int>
     */
    protected ?array $compiledPortMap = null;

    /**
     * Create a new CompiledRouteCollection instance.
     */
    public function __construct(array $compiled, array $attributes)
    {
        $this->compiled = $compiled;
        $this->attributes = $attributes;
        $this->routes = new RouteCollection;
    }

    /**
     * Add a Route instance to the collection.
     *
     * @throws LogicException
     */
    public function add(Route $route): Route
    {
        $this->ensureNoCrossPortConflictWithCompiledRoutes($route);

        return $this->routes->add($route);
    }

    /**
     * Ensure a dynamically-added route doesn't conflict with a compiled route on port.
     *
     * @throws LogicException
     */
    protected function ensureNoCrossPortConflictWithCompiledRoutes(Route $route): void
    {
        $this->compiledPortMap ??= $this->buildCompiledPortMap();

        $domainAndUri = $route->getDomain() . $route->uri();

        foreach ($route->methods() as $method) {
            $key = $method . $domainAndUri;

            if (array_key_exists($key, $this->compiledPortMap)
                && $this->compiledPortMap[$key] !== $route->getPort()
            ) {
                throw new LogicException(
                    "Cannot register [{$method} {$domainAndUri}] for multiple ports. "
                    . 'Same-path cross-port routes are not supported by the compiled matcher.'
                );
            }
        }
    }

    /**
     * Build the port lookup map from compiled route attributes.
     *
     * @return array<string, null|int>
     */
    protected function buildCompiledPortMap(): array
    {
        $map = [];

        foreach ($this->attributes as $attributes) {
            $domainAndUri = ($attributes['action']['domain'] ?? '') . $attributes['uri'];

            foreach ($attributes['methods'] as $method) {
                $map[$method . $domainAndUri] = $attributes['action']['port'] ?? null;
            }
        }

        return $map;
    }

    /**
     * Refresh the name look-up table.
     *
     * This is done in case any names are fluently defined or if routes are overwritten.
     */
    public function refreshNameLookups(): void
    {
    }

    /**
     * Refresh the action look-up table.
     *
     * This is done in case any actions are overwritten with new controllers.
     */
    public function refreshActionLookups(): void
    {
    }

    /**
     * Find the first route matching a given request.
     *
     * Fresh RequestContext per request for coroutine safety — a shared mutable
     * RequestContext would race under coroutine interleaving. The allocation
     * cost of one small object per request is negligible.
     *
     * No request duplication needed — trailing slashes are trimmed via rtrim()
     * on the path string and passed to match() directly, avoiding the overhead
     * of cloning the entire Request object. RequestBridge also normalizes
     * trailing slashes for real HTTP requests upstream.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function match(Request $request): Route
    {
        $context = new RequestContext(
            method: $request->getMethod(),
            host: $request->getHost(),
            scheme: $request->getScheme(),
            httpPort: $request->isSecure() ? 443 : (int) $request->getPort(),
            httpsPort: $request->isSecure() ? (int) $request->getPort() : 443,
            path: $request->getPathInfo(),
            queryString: $request->server->get('QUERY_STRING', ''),
        );

        $matcher = new CompiledUrlMatcher($this->compiled, $context);
        $path = rtrim($request->getPathInfo(), '/') ?: '/';

        $route = null;

        try {
            if ($result = $matcher->match($path)) {
                $route = $this->getByName($result['_route']);
            }
        } catch (ResourceNotFoundException|MethodNotAllowedException) {
            try {
                return $this->routes->match($request);
            } catch (NotFoundHttpException) {
            }
        }

        $routePort = $route?->getPort();

        if ($routePort !== null && $routePort !== (int) $request->getPort()) {
            try {
                return $this->routes->match($request);
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                $route = null;
            }
        }

        if ($route && $route->isFallback) {
            try {
                $dynamicRoute = $this->routes->match($request);

                if (! $dynamicRoute->isFallback) {
                    $route = $dynamicRoute;
                }
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            }
        }

        return $this->handleMatchedRoute($request, $route);
    }

    /**
     * Get routes from the collection by method.
     *
     * @return array<int|string, Route>
     */
    public function get(?string $method = null): array
    {
        if (is_null($method)) {
            return $this->getRoutes();
        }

        $routes = (new Collection($this->routeNamesByMethod()[$method] ?? []))
            ->mapWithKeys(function (string $name): array {
                $route = $this->getByName($name);

                return [$route->getDomain() . $route->uri => $route];
            })
            ->all();

        // Dynamically added routes take precedence over cached routes with the same URI...
        return $this->routes->get($method) + $routes;
    }

    /**
     * Determine if the route collection contains a given named route.
     */
    public function hasNamedRoute(string $name): bool
    {
        return isset($this->attributes[$name]) || $this->routes->hasNamedRoute($name);
    }

    /**
     * Get a route instance by its name.
     *
     * Returns cached Route objects for the lifetime of this collection.
     */
    public function getByName(string $name): ?Route
    {
        if (isset($this->attributes[$name])) {
            return $this->nameCache[$name]
                ??= $this->newRoute($this->attributes[$name]);
        }

        return $this->routes->getByName($name);
    }

    /**
     * Get a route instance by its controller action.
     */
    public function getByAction(string $action): ?Route
    {
        $name = $this->routeNameByAction()[$action] ?? null;

        if ($name !== null) {
            return $this->getByName($name);
        }

        return $this->routes->getByAction($action);
    }

    /**
     * Get all of the routes in the collection.
     *
     * @return array<int, Route>
     */
    public function getRoutes(): array
    {
        return (new Collection($this->attributes))
            ->map(function (array $attributes): Route {
                return $this->newRoute($attributes);
            })
            ->merge($this->routes->getRoutes())
            ->values()
            ->all();
    }

    /**
     * Get the route instances that should be pre-warmed.
     *
     * Returns the collection's cached Route instances — these
     * are the objects actually used during request matching. Unlike
     * getRoutes() which creates fresh throwaway objects every call.
     *
     * @return array<int, Route>
     */
    public function getWarmableRoutes(): array
    {
        $routes = [];

        foreach (array_keys($this->attributes) as $name) {
            $routes[] = $this->getByName((string) $name);
        }

        return array_merge($routes, $this->routes->getRoutes());
    }

    /**
     * Get all of the routes keyed by their HTTP verb / method.
     *
     * @return array<string, array<array-key, Route>>
     */
    public function getRoutesByMethod(): array
    {
        return (new Collection($this->routeNamesByMethod()))
            ->keys()
            ->merge(array_keys($this->routes->getRoutesByMethod()))
            ->unique()
            ->mapWithKeys(fn (string $method): array => [$method => $this->get($method)])
            ->all();
    }

    /**
     * Get all of the routes keyed by their name.
     *
     * @return array<array-key, Route>
     */
    public function getRoutesByName(): array
    {
        return (new Collection($this->getRoutes()))
            ->keyBy(function (Route $route): ?string {
                return $route->getName();
            })
            ->all();
    }

    /**
     * Get the cached route names grouped by the HTTP method they respond to.
     *
     * @return array<string, array<int, string>>
     */
    protected function routeNamesByMethod(): array
    {
        if (! is_null($this->routeNamesByMethod)) {
            return $this->routeNamesByMethod;
        }

        return $this->routeNamesByMethod = (new Collection($this->attributes))
            ->groupBy(fn (array $attributes): array => $attributes['methods'], preserveKeys: true)
            ->map(fn (Collection $group): array => $group->keys()
                ->map(fn (int|string $name): string => (string) $name)
                ->all())
            ->all();
    }

    /**
     * Get the cached route names keyed by their controller action.
     *
     * @return array<string, string>
     */
    protected function routeNameByAction(): array
    {
        if (! is_null($this->routeNameByAction)) {
            return $this->routeNameByAction;
        }

        return $this->routeNameByAction = (new Collection($this->attributes))
            ->map(fn (array $attributes): mixed => isset($attributes['action']['controller'])
                ? trim($attributes['action']['controller'], '\\')
                : ($attributes['action']['uses'] ?? null))
            ->filter(fn (mixed $action): bool => is_string($action))
            ->reverse()
            ->flip()
            ->map(fn (int|string $name): string => (string) $name)
            ->all();
    }

    /**
     * Resolve an array of attributes to a Route instance.
     */
    protected function newRoute(array $attributes): Route
    {
        if (empty($attributes['action']['prefix'] ?? '')) {
            $baseUri = $attributes['uri'];
        } else {
            $prefix = trim($attributes['action']['prefix'], '/');

            $baseUri = trim(implode(
                '/',
                array_slice(
                    explode('/', trim($attributes['uri'], '/')),
                    count($prefix !== '' ? explode('/', $prefix) : [])
                )
            ), '/');
        }

        return $this->router->newRoute($attributes['methods'], $baseUri === '' ? '/' : $baseUri, $attributes['action'])
            ->setFallback($attributes['fallback'])
            ->setDefaults($attributes['defaults'])
            ->setWheres($attributes['wheres'])
            ->setBindingFields($attributes['bindingFields'])
            ->block($attributes['lockSeconds'] ?? null, $attributes['waitSeconds'] ?? null)
            ->withTrashed($attributes['withTrashed'] ?? false);
    }

    /**
     * Set the router instance on the route.
     *
     * @return $this
     */
    public function setRouter(Router $router): static
    {
        $this->router = $router;

        return $this;
    }

    /**
     * Set the container instance on the route.
     *
     * Tests only. Swaps the container reference on the route collection used
     * by the singleton Router; per-request use races across coroutines.
     *
     * @return $this
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }
}
