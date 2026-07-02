<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Routing\Route as RouteObject;
use Hypervel\Routing\Router;

trait InteractsWithRouteMiddleware
{
    /**
     * Assert route middleware runtime order.
     */
    protected function assertRouteMiddlewareRunsBefore(string $routeName, string $before, string $after): void
    {
        $middleware = $this->resolvedMiddlewareForRoute($routeName);

        $this->assertContains($before, $middleware);
        $this->assertContains($after, $middleware);
        $this->assertLessThan(
            array_search($after, $middleware, true),
            array_search($before, $middleware, true),
        );
    }

    /**
     * Get resolved runtime middleware for a named route.
     *
     * @return array<int, mixed>
     */
    protected function resolvedMiddlewareForRoute(string $routeName): array
    {
        // Resolve the kernel so its constructor syncs middleware priority and aliases onto the router.
        $this->app->make(HttpKernel::class);

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->getRoutes()->refreshNameLookups();

        $route = $router->getRoutes()->getByName($routeName);

        $this->assertInstanceOf(RouteObject::class, $route);

        return $router->gatherRouteMiddleware($route);
    }
}
