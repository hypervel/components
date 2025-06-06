<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Router\Router;

/**
 * @method static void addServer(string $serverName, callable $callback)
 * @method static void group(string $prefix, callable|string $source, array $options = [])
 * @method static void addGroup(string $prefix, callable|string $source, array $options = [])
 * @method static \Hyperf\HttpServer\Router\RouteCollector getRouter()
 * @method static void model(string $param, string $modelClass)
 * @method static void bind(string $param, \Closure $callback)
 * @method static string|null getModelBinding(string $param)
 * @method static \Closure|null getExplicitBinding(string $param)
 * @method static void addRoute(string|string[] $httpMethod, string $route, array|string $handler, array $options = [])
 * @method static void get(string $route, null|array|callable|string $handler, array $options = [])
 * @method static void post(string $route, null|array|callable|string $handler, array $options = [])
 * @method static void put(string $route, null|array|callable|string $handler, array $options = [])
 * @method static void delete(string $route, null|array|callable|string $handler, array $options = [])
 * @method static void patch(string $route, null|array|callable|string $handler, array $options = [])
 * @method static void head(string $route, null|array|callable|string $handler, array $options = [])
 * @method static array getData()
 * @method static \FastRoute\RouteParser getRouteParser()
 * @method static void options(string $route, array|string $handler, array $options = [])
 * @method static void match(array $methods, string $route, array|string $handler, array $options = [])
 * @method static void any(string $route, array|string $handler, array $options = [])
 * @method static array getNamedRoutes()
 * @method static bool has(array|string $name)
 *
 * @see \Hypervel\Router\Router
 * @see \Hypervel\Router\RouteCollector
 */
class Route extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Router::class;
    }
}
