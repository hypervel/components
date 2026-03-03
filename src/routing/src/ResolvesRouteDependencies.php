<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Container\Util;
use Hypervel\Support\Arr;
use Hypervel\Support\Reflector;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

trait ResolvesRouteDependencies
{
    /**
     * Cached isEnum results keyed by class name.
     *
     * Persists for the worker lifetime — enum status never changes at runtime.
     * Bounded by the number of unique type-hinted classes in controller parameters.
     *
     * @var array<string, bool>
     */
    protected static array $enumCache = [];

    /**
     * Flush the static enum cache.
     */
    public static function flushEnumCache(): void
    {
        static::$enumCache = [];
    }

    /**
     * Resolve the object method's type-hinted dependencies.
     */
    protected function resolveClassMethodDependencies(array $parameters, object $instance, string $method): array
    {
        if (! method_exists($instance, $method)) {
            return $parameters;
        }

        return $this->resolveMethodDependencies(
            $parameters,
            new ReflectionMethod($instance, $method)
        );
    }

    /**
     * Resolve the given method's type-hinted dependencies.
     */
    public function resolveMethodDependencies(array $parameters, ReflectionFunctionAbstract $reflector): array
    {
        $instanceCount = 0;

        $values = array_values($parameters);

        $skippableValue = new stdClass();

        foreach ($reflector->getParameters() as $key => $parameter) {
            $instance = $this->transformDependency($parameter, $parameters, $skippableValue);

            if ($instance !== $skippableValue) {
                ++$instanceCount;

                $this->spliceIntoParameters($parameters, $key, $instance);
            } elseif (! isset($values[$key - $instanceCount])
                      && $parameter->isDefaultValueAvailable()) {
                $this->spliceIntoParameters($parameters, $key, $parameter->getDefaultValue());
            }

            $this->container->fireAfterResolvingAttributeCallbacks($parameter->getAttributes(), $instance);
        }

        return $parameters;
    }

    /**
     * Attempt to transform the given parameter into a class instance.
     */
    protected function transformDependency(ReflectionParameter $parameter, array $parameters, object $skippableValue): mixed
    {
        if ($attribute = Util::getContextualAttributeFromDependency($parameter)) {
            return $this->container->resolveFromAttribute($attribute);
        }

        $className = Reflector::getParameterClassName($parameter);

        // If the parameter has a type-hinted class, we will check to see if it is already in
        // the list of parameters. If it is we will just skip it as it is probably a model
        // binding and we do not want to mess with those; otherwise, we resolve it here.
        if ($className && ! $this->alreadyInParameters($className, $parameters)) {
            $isEnum = static::$enumCache[$className] ??= (new ReflectionClass($className))->isEnum();

            return $parameter->isDefaultValueAvailable()
                ? ($isEnum ? $parameter->getDefaultValue() : null)
                : $this->container->make($className);
        }

        return $skippableValue;
    }

    /**
     * Determine if an object of the given class is in a list of parameters.
     */
    protected function alreadyInParameters(string $class, array $parameters): bool
    {
        return ! is_null(Arr::first($parameters, fn (mixed $value): bool => $value instanceof $class));
    }

    /**
     * Splice the given value into the parameter list.
     */
    protected function spliceIntoParameters(array &$parameters, int $offset, mixed $value): void
    {
        array_splice(
            $parameters,
            $offset,
            0,
            [$value]
        );
    }
}
