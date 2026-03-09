<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Hypervel\Support\Collection;
use ReflectionMethod;

class ControllerDispatcher implements ControllerDispatcherContract
{
    use FiltersControllerMiddleware;
    use ResolvesRouteDependencies;

    /**
     * Cached ReflectionMethod instances keyed by "class::method".
     *
     * Persists for the worker lifetime — controller methods never change at runtime.
     * Bounded by the number of unique controller action methods.
     *
     * @var array<string, ReflectionMethod>
     */
    protected static array $reflectionCache = [];

    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The cached precognition dispatcher instance.
     */
    protected ?PrecognitionControllerDispatcher $precognitionDispatcher = null;

    /**
     * Create a new controller dispatcher instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Dispatch a request to a given controller and method.
     */
    public function dispatch(Route $route, mixed $controller, string $method): mixed
    {
        $request = RequestContext::getOrNull();

        if ($request?->attributes->get('precognitive_dispatch')) {
            return ($this->precognitionDispatcher ??= new PrecognitionControllerDispatcher($this->container))
                ->dispatch($route, $controller, $method);
        }

        $parameters = $this->resolveParameters($route, $controller, $method);

        if (method_exists($controller, 'callAction')) {
            return $controller->callAction($method, $parameters);
        }

        return $controller->{$method}(...array_values($parameters));
    }

    /**
     * Resolve the parameters for the controller.
     */
    protected function resolveParameters(Route $route, mixed $controller, string $method): array
    {
        return $this->resolveClassMethodDependencies(
            $route->parametersWithoutNulls(),
            $controller,
            $method
        );
    }

    /**
     * Resolve the object method's type-hinted dependencies.
     *
     * Overrides ResolvesRouteDependencies to use a static ReflectionMethod cache.
     */
    protected function resolveClassMethodDependencies(array $parameters, object $instance, string $method): array
    {
        if (! method_exists($instance, $method)) {
            return $parameters;
        }

        $key = get_class($instance) . '::' . $method;
        $reflector = static::$reflectionCache[$key] ??= new ReflectionMethod($instance, $method);

        return $this->resolveMethodDependencies($parameters, $reflector);
    }

    /**
     * Pre-warm the ReflectionMethod cache for a controller action.
     *
     * Called during server boot to populate reflection data before fork.
     */
    public static function warmReflection(string $class, string $method): void
    {
        $key = $class . '::' . $method;
        static::$reflectionCache[$key] ??= new ReflectionMethod($class, $method);
    }

    /**
     * Flush the static reflection cache.
     */
    public static function flushCache(): void
    {
        static::$reflectionCache = [];
    }

    /**
     * Get the middleware for the controller instance.
     */
    public function getMiddleware(mixed $controller, string $method): array
    {
        if (! method_exists($controller, 'getMiddleware')) {
            return [];
        }

        return (new Collection($controller->getMiddleware()))
            ->reject(fn (array $data): bool => static::methodExcludedByOptions($method, $data['options']))
            ->pluck('middleware')
            ->all();
    }
}
