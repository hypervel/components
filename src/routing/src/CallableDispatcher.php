<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

class CallableDispatcher implements CallableDispatcherContract
{
    use ResolvesRouteDependencies;

    /**
     * Cached ReflectionParameter arrays keyed by closure object ID.
     *
     * Only Closures are cached — they have stable spl_object_id() for the
     * worker lifetime. Other callable shapes (array, invokable object, string)
     * are not cached — they're extremely rare in route dispatch.
     *
     * @var array<int, array<int, ReflectionParameter>>
     */
    protected static array $reflectionCache = [];

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
            $reflectedParameters = static::$reflectionCache[spl_object_id($callable)]
                ??= (new ReflectionFunction($callable))->getParameters();
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
        static::$reflectionCache = [];
    }
}
