<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Support\Providers;

use Hypervel\Router\RouteFileCollector;
use Hypervel\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The route files for the application.
     */
    protected array $routes = [
    ];

    public function boot(): void
    {
        $this->app->get(RouteFileCollector::class)
            ->addRouteFiles($this->routes);
    }
}
