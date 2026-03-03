<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Support\Reflector;
use Hypervel\Support\Str;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

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
     * Extract the route action's signature parameters.
     */
    public static function fromAction(array $action, array $conditions = []): array
    {
        $callback = RouteAction::containsSerializedClosure($action)
            ? unserialize($action['uses'])->getClosure()
            : $action['uses'];

        $cacheKey = is_string($callback)
            ? $callback
            : 'closure_' . spl_object_id($callback);

        $parameters = static::$cache[$cacheKey] ??= is_string($callback)
            ? static::fromClassMethodString($callback)
            : (new ReflectionFunction($callback))->getParameters();

        return match (true) {
            ! empty($conditions['subClass']) => array_filter($parameters, fn (ReflectionParameter $p) => Reflector::isParameterSubclassOf($p, $conditions['subClass'])),
            ! empty($conditions['backedEnum']) => array_filter($parameters, fn (ReflectionParameter $p) => Reflector::isParameterBackedEnumWithStringBackingType($p)),
            default => $parameters,
        };
    }

    /**
     * Flush the static parameter cache.
     */
    public static function flushCache(): void
    {
        static::$cache = [];
    }

    /**
     * Get the parameters for the given class / method by string.
     *
     * @return array<int, ReflectionParameter>
     */
    protected static function fromClassMethodString(string $uses): array
    {
        [$class, $method] = Str::parseCallback($uses);

        if (! method_exists($class, $method) && Reflector::isCallable([$class, $method])) {
            return [];
        }

        return (new ReflectionMethod($class, $method))->getParameters();
    }
}
