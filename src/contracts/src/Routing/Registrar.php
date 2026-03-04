<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Routing;

use Closure;
use Hypervel\Routing\PendingResourceRegistration;
use Hypervel\Routing\Route;

interface Registrar
{
    /**
     * Register a new GET route with the router.
     */
    public function get(string $uri, array|string|callable $action): Route;

    /**
     * Register a new POST route with the router.
     */
    public function post(string $uri, array|string|callable $action): Route;

    /**
     * Register a new PUT route with the router.
     */
    public function put(string $uri, array|string|callable $action): Route;

    /**
     * Register a new DELETE route with the router.
     */
    public function delete(string $uri, array|string|callable $action): Route;

    /**
     * Register a new PATCH route with the router.
     */
    public function patch(string $uri, array|string|callable $action): Route;

    /**
     * Register a new OPTIONS route with the router.
     */
    public function options(string $uri, array|string|callable $action): Route;

    /**
     * Register a new route with the given verbs.
     */
    public function match(array|string $methods, string $uri, array|string|callable $action): Route;

    /**
     * Route a resource to a controller.
     */
    public function resource(string $name, string $controller, array $options = []): PendingResourceRegistration;

    /**
     * Create a route group with shared attributes.
     */
    public function group(array $attributes, Closure|array|string $routes): static;

    /**
     * Substitute the route bindings onto the route.
     */
    public function substituteBindings(Route $route): Route;

    /**
     * Substitute the implicit Eloquent model bindings for the route.
     */
    public function substituteImplicitBindings(Route $route): mixed;
}
