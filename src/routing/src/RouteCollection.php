<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Container\Container;
use Hypervel\Http\Request;
use Symfony\Component\Routing\RouteCollection as SymfonyRouteCollection;

class RouteCollection extends AbstractRouteCollection
{
    /**
     * An array of the routes keyed by method.
     *
     * @var array<string, array<string, Route>>
     */
    protected array $routes = [];

    /**
     * A flattened array of all of the routes.
     *
     * @var array<string, Route>
     */
    protected array $allRoutes = [];

    /**
     * A look-up table of routes by their names.
     *
     * @var array<string, Route>
     */
    protected array $nameList = [];

    /**
     * A look-up table of routes by controller action.
     *
     * @var array<string, Route>
     */
    protected array $actionList = [];

    /**
     * Add a Route instance to the collection.
     */
    public function add(Route $route): Route
    {
        $this->addToCollections($route);

        $this->addLookups($route);

        return $route;
    }

    /**
     * Add the given route to the arrays of routes.
     */
    protected function addToCollections(Route $route): void
    {
        $methods = $route->methods();
        $domainAndUri = $route->getDomain() . $route->uri();

        foreach ($methods as $method) {
            if ($route->getDomain()) {
                $domainRoutes = array_filter($this->routes[$method] ?? [], fn (Route $route) => $route->getDomain() !== null);

                $this->routes[$method] = $domainRoutes + [$domainAndUri => $route] + ($this->routes[$method] ?? []);
            } else {
                $this->routes[$method][$domainAndUri] = $route;
            }
        }

        if ($route->getDomain()) {
            $domainRoutes = array_filter($this->allRoutes, fn (Route $route) => $route->getDomain() !== null);

            $this->allRoutes = $domainRoutes + [implode('|', $methods) . $domainAndUri => $route] + $this->allRoutes;
        } else {
            $this->allRoutes[implode('|', $methods) . $domainAndUri] = $route;
        }
    }

    /**
     * Add the route to any look-up tables if necessary.
     */
    protected function addLookups(Route $route): void
    {
        // If the route has a name, we will add it to the name look-up table, so that we
        // will quickly be able to find the route associated with a name and not have
        // to iterate through every route every time we need to find a named route.
        if (($name = $route->getName()) && ! $this->inNameLookup($name)) {
            $this->nameList[$name] = $route;
        }

        // When the route is routing to a controller we will also store the action that
        // is used by the route. This will let us reverse route to controllers while
        // processing a request and easily generate URLs to the given controllers.
        $action = $route->getAction();

        if (($controller = $action['controller'] ?? null) && ! $this->inActionLookup($controller)) {
            $this->addToActionList($action, $route);
        }
    }

    /**
     * Add a route to the controller action dictionary.
     */
    protected function addToActionList(array $action, Route $route): void
    {
        $this->actionList[trim($action['controller'], '\\')] = $route;
    }

    /**
     * Determine if the given controller is in the action lookup table.
     */
    protected function inActionLookup(string $controller): bool
    {
        return array_key_exists($controller, $this->actionList);
    }

    /**
     * Determine if the given name is in the name lookup table.
     */
    protected function inNameLookup(string $name): bool
    {
        return array_key_exists($name, $this->nameList);
    }

    /**
     * Refresh the name look-up table.
     *
     * This is done in case any names are fluently defined or if routes are overwritten.
     */
    public function refreshNameLookups(): void
    {
        $this->nameList = [];

        foreach ($this->allRoutes as $route) {
            if (($name = $route->getName()) && ! $this->inNameLookup($name)) {
                $this->nameList[$name] = $route;
            }
        }
    }

    /**
     * Refresh the action look-up table.
     *
     * This is done in case any actions are overwritten with new controllers.
     */
    public function refreshActionLookups(): void
    {
        $this->actionList = [];

        foreach ($this->allRoutes as $route) {
            if (($controller = $route->getAction()['controller'] ?? null) && ! $this->inActionLookup($controller)) {
                $this->addToActionList($route->getAction(), $route);
            }
        }
    }

    /**
     * Find the first route matching a given request.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function match(Request $request): Route
    {
        $routes = $this->get($request->getMethod());

        // First, we will see if we can find a matching route for this current request
        // method. If we can, great, we can just return it so that it can be called
        // by the consumer. Otherwise we will check for routes with another verb.
        $route = $this->matchAgainstRoutes($routes, $request);

        return $this->handleMatchedRoute($request, $route);
    }

    /**
     * Get routes from the collection by method.
     *
     * @return array<int|string, Route>
     */
    public function get(?string $method = null): array
    {
        return is_null($method) ? $this->getRoutes() : ($this->routes[$method] ?? []);
    }

    /**
     * Determine if the route collection contains a given named route.
     */
    public function hasNamedRoute(string $name): bool
    {
        return ! is_null($this->getByName($name));
    }

    /**
     * Get a route instance by its name.
     */
    public function getByName(string $name): ?Route
    {
        return $this->nameList[$name] ?? null;
    }

    /**
     * Get a route instance by its controller action.
     */
    public function getByAction(string $action): ?Route
    {
        return $this->actionList[$action] ?? null;
    }

    /**
     * Get all of the routes in the collection.
     *
     * @return array<int, Route>
     */
    public function getRoutes(): array
    {
        return array_values($this->allRoutes);
    }

    /**
     * Get all of the routes keyed by their HTTP verb / method.
     *
     * @return array<string, array<string, Route>>
     */
    public function getRoutesByMethod(): array
    {
        return $this->routes;
    }

    /**
     * Get all of the routes keyed by their name.
     *
     * @return array<string, Route>
     */
    public function getRoutesByName(): array
    {
        return $this->nameList;
    }

    /**
     * Convert the collection to a Symfony RouteCollection instance.
     */
    public function toSymfonyRouteCollection(): SymfonyRouteCollection
    {
        $symfonyRoutes = parent::toSymfonyRouteCollection();

        $this->refreshNameLookups();

        return $symfonyRoutes;
    }

    /**
     * Convert the collection to a CompiledRouteCollection instance.
     */
    public function toCompiledRouteCollection(Router $router, Container $container): CompiledRouteCollection
    {
        ['compiled' => $compiled, 'attributes' => $attributes] = $this->compile();

        return (new CompiledRouteCollection($compiled, $attributes))
            ->setRouter($router)
            ->setContainer($container);
    }
}
