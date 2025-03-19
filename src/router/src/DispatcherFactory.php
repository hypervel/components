<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Hyperf\Contract\ContainerInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\Router\DispatcherFactory as BaseDispatcherFactory;
use Hyperf\HttpServer\Router\RouteCollector;

class DispatcherFactory extends BaseDispatcherFactory
{
    protected bool $initialized = false;

    public function __construct(protected ContainerInterface $container)
    {
        $this->routes = $container->get(RouteFileCollector::class)
            ->getRouteFiles();
        $this->initAnnotationRoute(AnnotationCollector::list());
    }

    public function initRoutes()
    {
        $this->initialized = true;

        MiddlewareManager::$container = [];

        foreach ($this->routes as $route) {
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
