<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
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
     * Pre-built Route objects keyed by name.
     *
     * Cached for the worker lifetime — routes are built once, reused forever.
     * Bounded by route count (known at boot), no per-request growth.
     *
     * @var array<string, Route>
     */
    protected static array $cachedRoutesByName = [];

    /**
     * Create a new CompiledRouteCollection instance.
     */
    public function __construct(array $compiled, array $attributes)
    {
        $this->compiled = $compiled;
        $this->attributes = $attributes;
        $this->routes = new RouteCollection();
    }

    /**
     * Add a Route instance to the collection.
     */
    public function add(Route $route): Route
    {
        return $this->routes->add($route);
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
     * No $request->duplicate() — trailing slash already normalized by RequestBridge.
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

        $route = null;

        try {
            if ($result = $matcher->matchRequest($request)) {
                $route = $this->getByName($result['_route']);
            }
        } catch (ResourceNotFoundException|MethodNotAllowedException) {
            try {
                return $this->routes->match($request);
            } catch (NotFoundHttpException) {
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
        return $this->getRoutesByMethod()[$method] ?? [];
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
     * Returns cached Route objects — routes are built once and reused for the
     * worker lifetime. No per-request Route reconstruction.
     */
    public function getByName(string $name): ?Route
    {
        if (isset($this->attributes[$name])) {
            return static::$cachedRoutesByName[$name]
                ??= $this->newRoute($this->attributes[$name]);
        }

        return $this->routes->getByName($name);
    }

    /**
     * Get a route instance by its controller action.
     */
    public function getByAction(string $action): ?Route
    {
        $attributes = (new Collection($this->attributes))->first(function (array $attributes) use ($action): bool {
            if (isset($attributes['action']['controller'])) {
                return trim($attributes['action']['controller'], '\\') === $action;
            }

            return $attributes['action']['uses'] === $action;
        });

        if ($attributes) {
            return $this->newRoute($attributes);
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
     * Returns the cached Route instances from $cachedRoutesByName — these
     * are the objects actually used during request matching. Unlike
     * getRoutes() which creates fresh throwaway objects every call.
     *
     * @return array<int, Route>
     */
    public function getWarmableRoutes(): array
    {
        $routes = [];

        foreach (array_keys($this->attributes) as $name) {
            $routes[] = $this->getByName($name);
        }

        return array_merge($routes, $this->routes->getRoutes());
    }

    /**
     * Get all of the routes keyed by their HTTP verb / method.
     *
     * @return array<string, array<string, Route>>
     */
    public function getRoutesByMethod(): array
    {
        return (new Collection($this->getRoutes()))
            ->groupBy(function (Route $route): array { // @phpstan-ignore argument.type (groupBy supports array-returning callbacks for multi-group assignment)
                return $route->methods();
            })
            ->map(function (Collection $routes): array {
                return $routes->mapWithKeys(function (Route $route): array {
                    return [$route->getDomain() . $route->uri => $route];
                })->all();
            })
            ->all();
    }

    /**
     * Get all of the routes keyed by their name.
     *
     * @return array<string, Route>
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
     * Flush the static route cache.
     */
    public static function flushCache(): void
    {
        static::$cachedRoutesByName = [];
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
     * @return $this
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }
}
