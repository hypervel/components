<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Closure;
use Hypervel\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

class BoundMethod
{
    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     *
     * @throws \ReflectionException
     * @throws \InvalidArgumentException
     */
    public static function call(Container $container, $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        if (is_string($callback) && ! $defaultMethod && method_exists($callback, '__invoke')) {
            $defaultMethod = '__invoke';
        }

        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }

        return static::callBoundMethod($container, $callback, function () use ($container, $callback, $parameters) {
            return $callback(...array_values(static::getMethodDependencies($container, $callback, $parameters)));
        });
    }

    /**
     * Call a string reference to a class using Class@method syntax.
     *
     * @throws \InvalidArgumentException
     */
    protected static function callClass(Container $container, string $target, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        $segments = explode('@', $target);

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        $method = count($segments) === 2
            ? $segments[1]
            : $defaultMethod;

        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call(
            $container,
            [$container->make($segments[0]), $method],
            $parameters
        );
    }

    /**
     * Call a method that has been bound to the container.
     */
    protected static function callBoundMethod(Container $container, callable $callback, mixed $default): mixed
    {
        if (! is_array($callback)) {
            return Util::unwrapIfClosure($default);
        }

        // Here we need to turn the array callable into a Class@method string we can use to
        // examine the container and see if there are any method bindings for this given
        // method. If there are, we can call this method binding callback immediately.
        $method = static::normalizeMethod($callback);

        if ($container->hasMethodBinding($method)) {
            return $container->callMethodBinding($method, $callback[0]);
        }

        return Util::unwrapIfClosure($default);
    }

    /**
     * Normalize the given callback into a Class@method string.
     */
    protected static function normalizeMethod(callable $callback): string
    {
        $class = is_string($callback[0]) ? $callback[0] : get_class($callback[0]);

        return "{$class}@{$callback[1]}";
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param  callable|string  $callback
     *
     * @throws \ReflectionException
     */
    protected static function getMethodDependencies(Container $container, $callback, array $parameters = []): array
    {
        $dependencies = [];

        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);
        }

        return array_merge($dependencies, array_values($parameters));
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param  callable|string  $callback
     *
     * @throws \ReflectionException
     */
    protected static function getCallReflector($callback): ReflectionFunctionAbstract
    {
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback);
        } elseif (is_object($callback) && ! $callback instanceof Closure) {
            $callback = [$callback, '__invoke'];
        }

        return is_array($callback)
            ? new ReflectionMethod($callback[0], $callback[1])
            : new ReflectionFunction($callback);
    }

    /**
     * Get the dependency for the given call parameter.
     *
     * @throws \Hypervel\Contracts\Container\BindingResolutionException
     */
    protected static function addDependencyForCallParameter(
        Container $container,
        ReflectionParameter $parameter,
        array &$parameters,
        array &$dependencies,
    ): void {
        $pendingDependencies = [];

        if (array_key_exists($paramName = $parameter->getName(), $parameters)) {
            $pendingDependencies[] = $parameters[$paramName];

            unset($parameters[$paramName]);
        } elseif ($attribute = Util::getContextualAttributeFromDependency($parameter)) {
            $pendingDependencies[] = $container->resolveFromAttribute($attribute);
        } elseif (! is_null($className = Util::getParameterClassName($parameter))) {
            if (array_key_exists($className, $parameters)) {
                $pendingDependencies[] = $parameters[$className];

                unset($parameters[$className]);
            } elseif ($parameter->isVariadic()) {
                $variadicDependencies = $container->make($className);

                $pendingDependencies = array_merge($pendingDependencies, is_array($variadicDependencies)
                    ? $variadicDependencies
                    : [$variadicDependencies]);
            } elseif ($parameter->isDefaultValueAvailable() && ! $container->bound($className)) {
                $pendingDependencies[] = $parameter->getDefaultValue();
            } else {
                $pendingDependencies[] = $container->make($className);
            }
        } elseif ($parameter->isDefaultValueAvailable()) {
            $pendingDependencies[] = $parameter->getDefaultValue();
        } elseif (! $parameter->isOptional() && ! array_key_exists($paramName, $parameters)) {
            $message = "Unable to resolve dependency [{$parameter}] in class {$parameter->getDeclaringClass()->getName()}";

            throw new BindingResolutionException($message);
        }

        foreach ($pendingDependencies as $dependency) {
            $container->fireAfterResolvingAttributeCallbacks($parameter->getAttributes(), $dependency);
        }

        $dependencies = array_merge($dependencies, $pendingDependencies);
    }

    /**
     * Determine if the given string is in Class@method syntax.
     */
    protected static function isCallableWithAtSign(mixed $callback): bool
    {
        return is_string($callback) && str_contains($callback, '@');
    }
}
