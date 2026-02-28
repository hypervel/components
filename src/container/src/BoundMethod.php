<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Closure;
use Hypervel\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionParameter;

class BoundMethod
{
    /**
     * Cache of method parameter recipes, keyed by "ClassName::methodName".
     *
     * Stores pre-computed ParameterRecipe arrays for deterministic callables
     * (array callables, Class@method strings, Class::method strings, invocable
     * objects). Closures and global function strings are not cached since they
     * lack a deterministic ClassName::methodName key.
     *
     * Persists for the worker lifetime. Cleared via clearMethodRecipeCache()
     * which Container::flush() calls for test isolation.
     *
     * @var array<string, ParameterRecipe[]>
     */
    protected static array $methodRecipes = [];

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public static function call(Container $container, callable|string $callback, array $parameters = [], ?string $defaultMethod = null): mixed
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
     * @throws InvalidArgumentException
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
    protected static function callBoundMethod(Container $container, callable|string $callback, mixed $default): mixed
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
    protected static function normalizeMethod(array $callback): string
    {
        $class = is_string($callback[0]) ? $callback[0] : get_class($callback[0]);

        return "{$class}@{$callback[1]}";
    }

    /**
     * Get cached parameter recipes for a method, computing on first access.
     *
     * @return ParameterRecipe[]
     */
    protected static function getMethodRecipe(string $className, string $methodName): array
    {
        $key = $className . '::' . $methodName;

        return static::$methodRecipes[$key] ??= static::computeMethodRecipe($className, $methodName);
    }

    /**
     * Compute parameter recipes for a method via reflection.
     *
     * @return ParameterRecipe[]
     */
    protected static function computeMethodRecipe(string $className, string $methodName): array
    {
        $reflector = ReflectionManager::reflectMethod($className, $methodName);
        $recipes = [];

        foreach ($reflector->getParameters() as $index => $param) {
            $recipes[$index] = new ParameterRecipe(
                name: $param->getName(),
                position: $index,
                declaringClassName: $param->getDeclaringClass()?->getName() ?? $className,
                className: Util::getParameterClassName($param),
                hasType: $param->hasType(),
                hasDefault: $param->isDefaultValueAvailable(),
                default: $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                isVariadic: $param->isVariadic(),
                isOptional: $param->isOptional(),
                allowsNull: $param->allowsNull(),
                attributes: $param->getAttributes(),
                contextualAttribute: Util::getContextualAttributeFromDependency($param),
                reflectionString: (string) $param,
            );
        }

        return $recipes;
    }

    /**
     * Clear the method recipe cache.
     */
    public static function clearMethodRecipeCache(): void
    {
        static::$methodRecipes = [];
    }

    /**
     * Resolve method parameters using cached recipe metadata.
     *
     * @param ParameterRecipe[] $recipes
     *
     * @throws BindingResolutionException
     */
    protected static function resolveMethodRecipeParameters(Container $container, array $recipes, array &$parameters): array
    {
        $dependencies = [];

        foreach ($recipes as $recipe) {
            $pendingDependencies = [];

            if (array_key_exists($recipe->name, $parameters)) {
                $pendingDependencies[] = $parameters[$recipe->name];
                unset($parameters[$recipe->name]);
            } elseif ($recipe->contextualAttribute !== null) {
                $pendingDependencies[] = $container->resolveFromAttribute($recipe->contextualAttribute);
            } elseif ($recipe->className !== null) {
                if (array_key_exists($recipe->className, $parameters)) {
                    $pendingDependencies[] = $parameters[$recipe->className];
                    unset($parameters[$recipe->className]);
                } elseif ($recipe->isVariadic) {
                    $variadicDependencies = $container->make($recipe->className);
                    $pendingDependencies = array_merge($pendingDependencies, is_array($variadicDependencies)
                        ? $variadicDependencies
                        : [$variadicDependencies]);
                } elseif ($recipe->hasDefault && ! $container->bound($recipe->className)) {
                    $pendingDependencies[] = $recipe->default;
                } else {
                    $pendingDependencies[] = $container->make($recipe->className);
                }
            } elseif ($recipe->hasDefault) {
                $pendingDependencies[] = $recipe->default;
            } elseif (! $recipe->isOptional && ! array_key_exists($recipe->name, $parameters)) {
                $message = "Unable to resolve dependency [{$recipe->reflectionString}] in class {$recipe->declaringClassName}";
                throw new BindingResolutionException($message);
            }

            foreach ($pendingDependencies as $dependency) {
                $container->fireAfterResolvingAttributeCallbacks($recipe->attributes, $dependency);
            }

            $dependencies = array_merge($dependencies, $pendingDependencies);
        }

        return array_merge($dependencies, array_values($parameters));
    }

    /**
     * Get all dependencies for a given method.
     *
     * For deterministic callables (array callables, Class::method strings,
     * invocable objects), uses cached ParameterRecipe metadata. For closures
     * and global function strings (which lack a deterministic key), falls
     * back to per-call reflection.
     *
     * @throws ReflectionException
     */
    protected static function getMethodDependencies(Container $container, callable|string $callback, array $parameters = []): array
    {
        // Array callables — use cached parameter recipes
        if (is_array($callback)) {
            $className = is_string($callback[0]) ? $callback[0] : $callback[0]::class;
            $recipes = static::getMethodRecipe($className, $callback[1]);

            return static::resolveMethodRecipeParameters($container, $recipes, $parameters);
        }

        // Invocable objects (not closures) — use cached recipes
        if (is_object($callback) && ! $callback instanceof Closure) {
            $recipes = static::getMethodRecipe($callback::class, '__invoke');

            return static::resolveMethodRecipeParameters($container, $recipes, $parameters);
        }

        // String callbacks with :: — use cached recipes
        if (is_string($callback) && str_contains($callback, '::')) {
            [$className, $methodName] = explode('::', $callback);
            $recipes = static::getMethodRecipe($className, $methodName);

            return static::resolveMethodRecipeParameters($container, $recipes, $parameters);
        }

        // Uncacheable callables: closures and global function strings.
        // These lack a deterministic ClassName::methodName key, so we fall
        // back to per-call reflection via addDependencyForCallParameter.
        $dependencies = [];

        foreach ((new ReflectionFunction($callback))->getParameters() as $parameter) {
            static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);
        }

        return array_merge($dependencies, array_values($parameters));
    }

    /**
     * Get the dependency for the given call parameter.
     *
     * @throws BindingResolutionException
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
