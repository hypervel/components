<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\Router\DispatcherFactory as BaseDispatcherFactory;
use Hyperf\HttpServer\Router\RouteCollector;
use Hypervel\Contracts\Container\Container;

class DispatcherFactory extends BaseDispatcherFactory
{
    protected bool $initialized = false;

    public function __construct(protected Container $container)
    {
        $this->initAnnotationRoute(AnnotationCollector::list());
    }

    public function initRoutes(): void
    {
        $this->initialized = true;

        MiddlewareManager::$container = [];

        // Fetch route files at initialization time
        // Ensures routes added via loadRoutesFrom() in service providers are included
        $routes = $this->container->make(RouteFileCollector::class)->getRouteFiles();

        foreach ($routes as $route) {
            if (file_exists($route)) {
                require $route;
            }
        }
    }

    public function getRouter(string $serverName): RouteCollector
    {
        if (! $this->initialized) {
            $this->initRoutes();
        }

        if (isset($this->routers[$serverName])) {
            return $this->routers[$serverName];
        }

        return $this->routers[$serverName] = $this->container->make(RouteCollector::class, ['server' => $serverName]);
    }
}
