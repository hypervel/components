<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Router\DispatcherFactory;
use RuntimeException;

/**
 * @mixin \Hyperf\HttpServer\Router\RouteCollector
 */
class Router
{
    protected string $serverName = 'http';

    public function __construct(protected DispatcherFactory $dispatcherFactory)
    {
    }

    public function addServer(string $serverName, callable $callback): void
    {
        $this->serverName = $serverName;
        $callback();
        $this->serverName = 'http';
    }

    public function __call(string $name, array $arguments)
    {
        return $this->dispatcherFactory
            ->getRouter($this->serverName)
            ->{$name}(...$arguments);
    }

    public function group(string $prefix, callable|string $source, array $options = []): void
    {
        if (is_string($source)) {
            $source = $this->registerRouteFile($source);
        }

        $this->dispatcherFactory
            ->getRouter($this->serverName)
            ->addGroup($prefix, $source, $options);
    }

    public function addGroup(string $prefix, callable|string $source, array $options = []): void
    {
        $this->group($prefix, $source, $options);
    }

    protected function registerRouteFile(string $routeFile): Closure
    {
        if (! file_exists($routeFile)) {
            throw new RuntimeException("Route file does not exist at path `{$routeFile}`.");
        }

        return fn () => require $routeFile;
    }

    public function getRouter()
    {
        return $this->dispatcherFactory
            ->getRouter($this->serverName);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return ApplicationContext::getContainer()
            ->get(Router::class)
            ->{$name}(...$arguments);
    }
}
