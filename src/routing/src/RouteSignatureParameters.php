<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Closure;
use Hypervel\Support\Reflector;
use Hypervel\Support\Str;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use WeakMap;

class RouteSignatureParameters
{
    /**
     * Cached reflection parameters keyed by action string.
     *
     * Reflection is resolved once per action and persists for the worker
     * lifetime. Filtering (subClass, backedEnum) is applied per call since
     * the same action may be queried with different conditions.
     *
     * @var array<string, array<int, ReflectionParameter>>
     */
    protected static array $cache = [];

    /**
     * Cached reflection parameters keyed by closure object.
     *
     * WeakMap ensures entries disappear with the closure, preventing stale
     * signature reuse when object IDs are recycled during a long worker lifetime.
     *
     * @var null|WeakMap<Closure, array<int, ReflectionParameter>>
     */
    protected static ?WeakMap $closureCache = null;

    /**
     * Extract the route action's signature parameters.
     */
    public static function fromAction(array $action, array $conditions = []): array
    {
        $callback = RouteAction::containsSerializedClosure($action)
            ? unserialize($action['uses'])->getClosure()
            : $action['uses'];

        if (is_string($callback)) {
            $parameters = static::$cache[$callback] ??= static::fromClassMethodString($callback);
        } else {
            $closureCache = static::$closureCache ??= new WeakMap;

            if (! isset($closureCache[$callback])) {
                $closureCache[$callback] = (new ReflectionFunction($callback))->getParameters();
            }

            $parameters = $closureCache[$callback];
        }

        return match (true) {
            ! empty($conditions['subClass']) => array_filter($parameters, fn (ReflectionParameter $p) => Reflector::isParameterSubclassOf($p, $conditions['subClass'])),
            ! empty($conditions['backedEnum']) => array_filter($parameters, fn (ReflectionParameter $p) => Reflector::isParameterBackedEnumWithStringBackingType($p)),
            default => $parameters,
        };
    }

    /**
     * Flush the static parameter cache.
     *
     * Boot or tests only. Clears the process-wide signature caches shared by
     * every coroutine; next signature lookup re-reflects.
     */
    public static function flushCache(): void
    {
        static::$cache = [];
        static::$closureCache = new WeakMap;
    }

    /**
     * Get the parameters for the given class / method by string.
     *
     * @return array<int, ReflectionParameter>
     */
    protected static function fromClassMethodString(string $uses): array
    {
        [$class, $method] = Str::parseCallback($uses);

        // Hypervel diverges from Laravel here. Laravel's equivalent calls
        // Reflector::isCallable($class, $method) — a buggy two-scalar call where
        // $method is passed as the $syntaxOnly bool, making is_callable() return
        // true for any valid class string regardless of whether the method exists.
        // The bug accidentally produces the right behavior — return [] for missing
        // methods — via the wrong mechanism.
        //
        // Simply fixing the call to Reflector::isCallable([$class, $method]) would
        // turn missing-method routes into ReflectionException during implicit
        // binding, wayfinder:generate, route:cache, and any other tooling that
        // walks the route collection. That's worse than the bug. We bypass
        // isCallable entirely: lenient for missing methods (dispatch surfaces a
        // clear error with route context), strict for missing classes.
        if (class_exists($class) && ! method_exists($class, $method)) {
            return [];
        }

        return (new ReflectionMethod($class, $method))->getParameters();
    }
}
