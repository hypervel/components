<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Router\Router;

/**
 * @method static void addServer(string $serverName, callable $callback)
 * @method static void group(string $prefix, callable|string $source, array $options = [])
 * @method static void addGroup(string $prefix, callable|string $source, array $options = [])
 * @method static void getRouter()
 * @method static void model(string $param, string $modelClass)
 * @method static void bind(string $param, string|Closure $modelClass)
 * @method static null|string getModelBinding(string $param)
 * @method static null|Closure getExplicitBinding(string $param)
 * @method static void addRoute(string|string[] $httpMethod, string $route, array|string $handler, array $options = [])
 * @method static void get(string $route, array|callable|null|string $handler, array $options = [])
 * @method static void post(string $route, array|callable|null|string $handler, array $options = [])
 * @method static void put(string $route, array|callable|null|string $handler, array $options = [])
 * @method static void delete(string $route, array|callable|null|string $handler, array $options = [])
 * @method static void patch(string $route, array|callable|null|string $handler, array $options = [])
 * @method static void head(string $route, array|callable|null|string $handler, array $options = [])
 * @method static array getData()
 * @method static \FastRoute\RouteParser getRouteParser()
 *
 * @see \Hypervel\Router\Router
 */
class Route extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Router::class;
    }
}
