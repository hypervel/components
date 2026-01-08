<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Closure;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Di\ClosureDefinitionCollectorInterface;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hypervel\Container\Contracts\Container as ContainerContract;
use InvalidArgumentException;
use ReflectionException;

class BoundMethod
{
    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string $callback
     *
     * @throws ReflectionException
     * @throws BindingResolutionException
     */
    public static function call(ContainerContract $container, $callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        if (is_string($callback) && ! $defaultMethod && method_exists($callback, '__invoke')) {
            $defaultMethod = '__invoke';
        }

        // Convert string callable from Class@method to [Class, method]
        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }

        // Closure call
        if ($callback instanceof Closure) {
            $parameters = static::getClosureDependencies($container, $callback, $parameters);
            return $callback(...$parameters);
        }

        // object method call
        if (is_object($callback) && method_exists($callback, '__invoke')) {
            $callback = [$callback, '__invoke'];
        }

        // static method call
        if (is_string($callback) && str_contains($callback, '::')) {
            $callback = explode('::', $callback);
        }

        // array callable
        if (is_array($callback)) {
            return static::callBoundMethod($container, $callback, function () use ($container, $callback, $parameters) {
                [$class, $method] = $callback;

                return $callback(...static::getMethodDependencies(
                    $container,
                    is_object($class) ? get_class($class) : $class,
                    $method,
                    $parameters
                ));
            });
        }

        $callableName = 'Unknown';
        if (is_object($callback)) {
            $callableName = get_class($callback);
        } elseif (is_string($callback)) {
            $callableName = $callback;
        }

        throw new BindingResolutionException("Invalid callable `{$callableName}` provided.");
    }

    protected static function getDependencyParameters(ContainerContract $container, array $definitions, string $callableName, array $parameters): array
    {
        $result = [];

        foreach ($definitions as $key => $definition) {
            $value = null;
            if (array_key_exists($paramName = $definition->getMeta('name'), $parameters)) {
                $value = $parameters[$paramName];
                unset($parameters[$paramName]);
            } elseif (array_key_exists($key, $parameters)) {
                $value = $parameters[$key];
                unset($parameters[$key]);
            } elseif (array_key_exists($definitionName = $definition->getName(), $parameters)) {
                $value = $parameters[$definitionName];
                unset($parameters[$definitionName]);
            }

            if ($value === null) {
                if ($definition->getMeta('defaultValueAvailable')) {
                    $result[] = $definition->getMeta('defaultValue');
                } elseif ($container->has($definitionName = $definition->getName())) {
                    $result[] = $container->get($definitionName);
                } elseif ($definition->allowsNull()) {
                    $result[] = null;
                } else {
                    $callableName = $callableName === 'Closure'
                        ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[4]['class']
                        : $callableName;

                    throw new BindingResolutionException("Unable to resolve dependency [Parameter #{$key} [ <required> \${$paramName} ]] in class {$callableName}");
                }
            } else {
                $result[] = $container->get(NormalizerInterface::class)->denormalize($value, $definition->getName());
            }
        }

        return array_values(
            array_merge($result, $parameters)
        );
    }

    /**
     * Call a string reference to a class using Class@method syntax.
     *
     * @param string $target
     * @param null|string $defaultMethod
     *
     * @throws InvalidArgumentException
     */
    protected static function callClass(ContainerContract $container, $target, array $parameters = [], $defaultMethod = null): mixed
    {
        $segments = explode('@', $target);

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        $method = count($segments) === 2
            ? $segments[1] : $defaultMethod;

        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call(
            $container,
            [$container->get($segments[0]), $method],
            $parameters
        );
    }

    /**
     * Call a method that has been bound to the container.
     *
     * @param callable $callback
     * @param mixed $default
     */
    protected static function callBoundMethod(ContainerContract $container, $callback, $default): mixed
    {
        if (! is_array($callback)) {
            return static::unwrapIfClosure($default);
        }

        // Here we need to turn the array callable into a Class@method string we can use to
        // examine the container and see if there are any method bindings for this given
        // method. If there are, we can call this method binding callback immediately.
        $method = static::normalizeMethod($callback);

        if ($container->hasMethodBinding($method)) {
            return $container->callMethodBinding($method, $callback[0]);
        }

        return static::unwrapIfClosure($default);
    }

    protected static function unwrapIfClosure($value, ...$args)
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }

    /**
     * Normalize the given callback into a Class@method string.
     *
     * @param callable $callback
     */
    protected static function normalizeMethod($callback): string
    {
        $class = is_string($callback[0]) ? $callback[0] : get_class($callback[0]);

        return "{$class}@{$callback[1]}";
    }

    /**
     * Get all dependencies for a given method.
     *
     * @throws ReflectionException
     */
    protected static function getMethodDependencies(ContainerContract $container, string $class, string $method, array $parameters = []): array
    {
        $definitions = $container->get(MethodDefinitionCollectorInterface::class)
            ->getParameters($class, $method);

        return static::getDependencyParameters($container, $definitions, $class, $parameters);
    }

    /**
     * Get all dependencies for a given method.
     *
     * @throws ReflectionException
     */
    protected static function getClosureDependencies(ContainerContract $container, Closure $closure, array $parameters = []): array
    {
        $definitions = $container->get(ClosureDefinitionCollectorInterface::class)
            ->getParameters($closure);

        return static::getDependencyParameters($container, $definitions, 'Closure', $parameters);
    }

    /**
     * Determine if the given string is in Class@method syntax.
     *
     * @param mixed $callback
     */
    protected static function isCallableWithAtSign($callback): bool
    {
        return is_string($callback) && str_contains($callback, '@');
    }
}
