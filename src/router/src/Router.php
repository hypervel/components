<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Model;
use Hyperf\HttpServer\Request;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\RouteCollector;
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

    public function getRouter(): RouteCollector
    {
        return $this->dispatcherFactory
            ->getRouter($this->serverName);
    }

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

    public function bind(string $param, Closure $callback): void
    {
        $this->explicitBindings[$param] = $callback;
    }

    public function getModelBinding(string $param): ?string
    {
        return $this->modelBindings[$param] ?? null;
    }

    public function getExplicitBinding(string $param): ?Closure
    {
        return $this->explicitBindings[$param] ?? null;
    }

    public function current(): ?Dispatched
    {
        return ApplicationContext::getContainer()
            ->get(Request::class)
            ->getAttribute(Dispatched::class);
    }

    public function currentRouteName(): ?string
    {
        if (! $handler = $this->current()->handler ?? null) {
            return null;
        }

        return $handler->options['as'] ?? null;
    }

    public static function __callStatic(string $name, array $arguments)
    {
        return ApplicationContext::getContainer()
            ->get(Router::class)
            ->{$name}(...$arguments);
    }
}
