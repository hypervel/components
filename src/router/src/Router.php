<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Closure;
use Hypervel\Context\ApplicationContext;
use Hyperf\HttpServer\Request;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\RouteCollector;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\DispatchedRoute;
use RuntimeException;

/**
 * @mixin \Hyperf\HttpServer\Router\RouteCollector
 */
class Router
{
    protected string $serverName = 'http';

    /**
     * Customized route parameters for model bindings.
     *
     * @var array<string, class-string>
     */
    protected array $modelBindings = [];

    /**
     * Customized route parameters for explicit bindings.
     *
     * @var array<string, Closure>
     */
    protected array $explicitBindings = [];

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

    /**
     * Register a route group with a prefix and source.
     */
    public function group(string $prefix, callable|string $source, array $options = []): void
    {
        if (is_string($source)) {
            $source = $this->registerRouteFile($source);
        }

        $this->dispatcherFactory
            ->getRouter($this->serverName)
            ->addGroup($prefix, $source, $options);
    }

    /**
     * Register a route group with a prefix and source.
     */
    public function addGroup(string $prefix, callable|string $source, array $options = []): void
    {
        $this->group($prefix, $source, $options);
    }

    /**
     * Register a route file.
     *
     * @throws RuntimeException
     */
    protected function registerRouteFile(string $routeFile): Closure
    {
        if (! file_exists($routeFile)) {
            throw new RuntimeException("Route file does not exist at path `{$routeFile}`.");
        }

        return fn () => require $routeFile;
    }

    /**
     * Get the route collector for the current server.
     */
    public function getRouter(): RouteCollector
    {
        return $this->dispatcherFactory
            ->getRouter($this->serverName);
    }

    /**
     * Register a model binder for a wildcard.
     */
    public function model(string $param, string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            throw new RuntimeException("Model class `{$modelClass}` does not exist.");
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException("Model class `{$modelClass}` must be a subclass of `Model`.");
        }

        $this->modelBindings[$param] = $modelClass;
    }

    /**
     * Add a new route parameter binder.
     */
    public function bind(string $param, Closure $callback): void
    {
        $this->explicitBindings[$param] = $callback;
    }

    /**
     * Get the model binding for a given route parameter.
     */
    public function getModelBinding(string $param): ?string
    {
        return $this->modelBindings[$param] ?? null;
    }

    /**
     * Get the explicit binding for a given route parameter.
     */
    public function getExplicitBinding(string $param): ?Closure
    {
        return $this->explicitBindings[$param] ?? null;
    }

    /**
     * Get the currently dispatched route instance.
     */
    public function current(): ?DispatchedRoute
    {
        return ApplicationContext::getContainer()
            ->get(Request::class)
            ->route();
    }

    /**
     * Get the currently dispatched route instance.
     */
    public function getCurrentRoute(): ?DispatchedRoute
    {
        return $this->current();
    }

    /**
     * Get the current route name.
     */
    public function currentRouteName(): ?string
    {
        if (! $route = $this->current() ?? null) {
            return null;
        }

        return $route->getName();
    }

    /**
     * Get a route parameter for the current route.
     */
    public function input(string $key, ?string $default = null): mixed
    {
        if (! $route = $this->current() ?? null) {
            return $default;
        }

        return $route->parameter($key, $default);
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return ApplicationContext::getContainer()
            ->get(Router::class)
            ->{$name}(...$arguments);
    }
}
