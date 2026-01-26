<?php

declare(strict_types=1);

namespace Hypervel\Router;

use Closure;
use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\Router\RouteCollector as BaseRouteCollector;
use Hypervel\Support\Arr;
use InvalidArgumentException;

class RouteCollector extends BaseRouteCollector
{
    /**
     * All of the named routes and data pairs.
     */
    protected array $namedRoutes = [];

    /**
     * Adds a OPTIONS route to the collection.
     *
     * This is simply an alias of $this->addRoute('OPTIONS', $route, $handler)
     * @param array|string $handler
     */
    public function options(string $route, mixed $handler, array $options = []): void
    {
        $this->addRoute('OPTIONS', $route, $handler, $options);
    }

    /**
     * Adds custom methods route to the collection.
     *
     * This is simply an alias of $this->addRoute($methods, $route, $handler)
     * @param array|string $handler
     */
    public function match(array $methods, string $route, mixed $handler, array $options = []): void
    {
        $this->addRoute($methods, $route, $handler, $options);
    }

    /**
     * Adds a GET, POST, PUT, DELETE, PATCH, HEAD, OPTIONS route to the collection.
     *
     * This is simply an alias of $this->addRoute([GET, POST, PUT, DELETE, PATCH, HEAD, OPTIONS], $route, $handler)
     * @param array|string $handler
     */
    public function any(string $route, mixed $handler, array $options = []): void
    {
        $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'], $route, $handler, $options);
    }

    public function addRoute(array|string $httpMethod, string $route, mixed $handler, array $options = []): void
    {
        $route = $this->getRouteWithGroupPrefix(
            $this->getRouteWithPrefix($route, $options['prefix'] ?? '/')
        );
        $routeDataList = $this->routeParser->parse($route);

        [$handler, $options] = $this->parseHandlerAndOptions($handler, $options);

        $options = $this->mergeOptions($this->currentGroupOptions, $options);

        foreach ((array) $httpMethod as $method) {
            $method = strtoupper($method);

            foreach ($routeDataList as $routeData) {
                $this->dataGenerator->addRoute($method, $routeData, new RouteHandler($handler, $route, $options));

                if (isset($options['as'])) {
                    $this->namedRoutes[$options['as']] = $routeData;
                }
            }

            // Store middleware without filtering - exclusions are applied after group expansion
            MiddlewareManager::addMiddlewares($this->server, $route, $method, Arr::wrap($options['middleware'] ?? []));

            // Store exclusions separately for post-expansion filtering
            MiddlewareExclusionManager::addExcluded($this->server, $route, $method, Arr::wrap($options['without_middleware'] ?? []));
        }
    }

    /**
     * Get all of the defined named routes.
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Check if the given route name exists.
     */
    public function has(array|string $name): bool
    {
        return empty(
            array_diff_key(
                array_flip(Arr::wrap($name)),
                $this->getNamedRoutes()
            )
        );
    }

    protected function mergeOptions(array $origin, array $options): array
    {
        if (isset($origin['as'])) {
            $options['as'] = $origin['as'] . (isset($options['as']) ? '.' . $options['as'] : '');
        }

        unset($origin['as']);

        return array_merge_recursive($origin, $options);
    }

    private function parseHandlerAndOptions(mixed $handler, array $options): array
    {
        if (! is_array($handler) || ! empty($options)) {
            return [$this->getDecoratedHandler($handler), $options];
        }

        $options = $handler;
        $handler = $this->parseAction($options);
        $options = $this->cleanOptions($options);

        return [$handler, $options];
    }

    private function getDecoratedHandler(mixed $handler): mixed
    {
        if (! is_string($handler)) {
            return $handler;
        }

        if ($namespace = $this->currentGroupOptions['namespace'] ?? null) {
            return $namespace . '\\' . $handler;
        }

        return $handler;
    }

    private function parseAction(array $options): mixed
    {
        if (count($options) === 2 && array_keys($options) === [0, 1]) {
            $options[0] = $this->getDecoratedHandler($options[0]);
            return $options;
        }

        if (isset($options['uses'])) {
            return $this->getDecoratedHandler($options['uses']);
        }

        if (isset($options[0]) && $options[0] instanceof Closure) {
            return $options[0];
        }

        throw new InvalidArgumentException('Invalid route action: ' . json_encode($options));
    }

    private function cleanOptions(array $options): array
    {
        return array_diff_key($options, array_flip([0, 'uses']));
    }

    private function getRouteWithGroupPrefix(string $route): string
    {
        return $this->getRouteWithPrefix(
            $this->getRouteWithPrefix($route, $this->currentGroupPrefix),
            $this->currentGroupOptions['prefix'] ?? '/'
        );
    }

    private function getRouteWithPrefix(string $route, string $prefix): string
    {
        $prefix = trim($prefix, '/');
        $route = trim($route, '/');

        if (empty($prefix) || $prefix === '/') {
            return $route ? "/{$route}" : '/';
        }

        return "/{$prefix}" . ($route ? "/{$route}" : '');
    }
}
