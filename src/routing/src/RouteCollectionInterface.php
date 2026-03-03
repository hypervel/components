<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Http\Request;

interface RouteCollectionInterface
{
    /**
     * Add a Route instance to the collection.
     */
    public function add(Route $route): Route;

    /**
     * Refresh the name look-up table.
     *
     * This is done in case any names are fluently defined or if routes are overwritten.
     */
    public function refreshNameLookups(): void;

    /**
     * Refresh the action look-up table.
     *
     * This is done in case any actions are overwritten with new controllers.
     */
    public function refreshActionLookups(): void;

    /**
     * Find the first route matching a given request.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function match(Request $request): Route;

    /**
     * Get routes from the collection by method.
     *
     * @return array<int, Route>
     */
    public function get(?string $method = null): array;

    /**
     * Determine if the route collection contains a given named route.
     */
    public function hasNamedRoute(string $name): bool;

    /**
     * Get a route instance by its name.
     */
    public function getByName(string $name): ?Route;

    /**
     * Get a route instance by its controller action.
     */
    public function getByAction(string $action): ?Route;

    /**
     * Get the route instances that should be pre-warmed.
     *
     * @return array<int, Route>
     */
    public function getWarmableRoutes(): array;

    /**
     * Get all of the routes in the collection.
     *
     * @return array<int, Route>
     */
    public function getRoutes(): array;

    /**
     * Get all of the routes keyed by their HTTP verb / method.
     *
     * @return array<string, array<string, Route>>
     */
    public function getRoutesByMethod(): array;

    /**
     * Get all of the routes keyed by their name.
     *
     * @return array<string, Route>
     */
    public function getRoutesByName(): array;
}
