<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Foundation\Routing\PrecognitionCallableDispatcher;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use WeakMap;

class CallableDispatcher implements CallableDispatcherContract
{
    use ResolvesRouteDependencies;

    /**
     * Cached ReflectionParameter arrays keyed by callable object.
     *
     * WeakMap ensures cached reflection metadata disappears when the callable
     * itself is no longer referenced, so later objects cannot inherit stale
     * parameter lists via recycled object IDs.
     *
     * @var null|WeakMap<object, array<int, ReflectionParameter>>
     */
    protected static ?WeakMap $reflectionCache = null;

    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The cached precognition dispatcher instance.
     */
    protected ?PrecognitionCallableDispatcher $precognitionDispatcher = null;

    /**
     * Create a new callable dispatcher instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Dispatch a request to a given callable.
     */
    public function dispatch(Route $route, callable $callable): mixed
    {
        $request = RequestContext::getOrNull();

        if ($request?->attributes->get('precognitive_dispatch')) {
            return ($this->precognitionDispatcher ??= new PrecognitionCallableDispatcher($this->container))
                ->dispatch($route, $callable);
        }

        return $callable(...array_values($this->resolveParameters($route, $callable)));
    }

    /**
     * Resolve the parameters for the callable.
     *
     * Routes supply closures or invokable objects, while the public dispatcher
     * contract also permits other native callable forms.
     */
    protected function resolveParameters(Route $route, callable $callable): array
    {
        if (is_object($callable)) {
            $reflectionCache = static::$reflectionCache ??= new WeakMap;

            if (! isset($reflectionCache[$callable])) {
                $reflectionCache[$callable] = $callable instanceof Closure
                    ? (new ReflectionFunction($callable))->getParameters()
                    : (new ReflectionMethod($callable, '__invoke'))->getParameters();
            }

            $reflectedParameters = $reflectionCache[$callable];
        } elseif (is_array($callable)) {
            $reflectedParameters = (new ReflectionMethod($callable[0], $callable[1]))->getParameters();
        } else {
            $reflectedParameters = (new ReflectionFunction($callable))->getParameters();
        }

        return $this->resolveMethodDependencies($route->parametersWithoutNulls(), $reflectedParameters);
    }

    /**
     * Flush the static reflection cache.
     *
     * Boot or tests only. Clears the process-wide reflection cache shared by
     * every coroutine; next dispatch re-reflects.
     */
    public static function flushCache(): void
    {
        static::$reflectionCache = new WeakMap;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushCache();
        static::flushEnumCache();
    }
}
