<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Router;

/**
 * @method static void addRoute($httpMethod, string $route, $handler, array $options = [])
 * @method static void addGroup($prefix, callable $callback, array $options = [])
 * @method static void get($route, $handler, array $options = [])
 * @method static void post($route, $handler, array $options = [])
 * @method static void put($route, $handler, array $options = [])
 * @method static void delete($route, $handler, array $options = [])
 * @method static void patch($route, $handler, array $options = [])
 * @method static void head($route, $handler, array $options = [])
 */
class Router
{
    protected static string $serverName = 'http';

    protected static ?DispatcherFactory $factory = null;

    public static function __callStatic($name, $arguments)
    {
        $router = static::$factory->getRouter(static::$serverName);
        return $router->{$name}(...$arguments);
    }

    public static function addServer(string $serverName, callable $callback)
    {
        static::$serverName = $serverName;
        $callback();
        static::$serverName = 'http';
    }

    public static function init(DispatcherFactory $factory)
    {
        static::$factory = $factory;
    }
}
