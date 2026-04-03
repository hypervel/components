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
     * Cached ReflectionParameter arrays keyed by closure object.
     *
     * WeakMap ensures cached reflection metadata disappears when the closure
     * itself is no longer referenced, so later closures cannot inherit stale
     * parameter lists via recycled object IDs.
     *
     * @var null|WeakMap<Closure, array<int, ReflectionParameter>>
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
     * Handles all callable shapes that can reach this method via Route::runCallable():
     * - Closure (most common — direct closure or deserialized SerializableClosure)
     * - Array callable [ClassName::class, 'method'] (via RouteAction::findCallable())
     * - Invokable object (via RouteAction::parse() with non-array callable)
     * - String function name (theoretical — makeInvokable() catches most)
     */
    protected function resolveParameters(Route $route, callable $callable): array
    {
        if ($callable instanceof Closure) {
            $reflectionCache = static::$reflectionCache ??= new WeakMap();

            if (! isset($reflectionCache[$callable])) {
                $reflectionCache[$callable] = (new ReflectionFunction($callable))->getParameters();
            }

            $reflectedParameters = $reflectionCache[$callable];
        } elseif (is_array($callable)) {
            $reflectedParameters = (new ReflectionMethod($callable[0], $callable[1]))->getParameters();
        } elseif (is_object($callable)) {
            $reflectedParameters = (new ReflectionMethod($callable, '__invoke'))->getParameters();
        } else {
            $reflectedParameters = (new ReflectionFunction($callable))->getParameters();
        }

        return $this->resolveMethodDependencies($route->parametersWithoutNulls(), $reflectedParameters);
    }

    /**
     * Flush the static reflection cache.
     */
    public static function flushCache(): void
    {
        static::$reflectionCache = new WeakMap();
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
