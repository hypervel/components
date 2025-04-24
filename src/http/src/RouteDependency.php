<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Closure;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Di\ClosureDefinitionCollectorInterface;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hyperf\Di\ReflectionType;
use Hyperf\HttpServer\Router\Dispatched;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class RouteDependency
{
    /**
     * All of the resolved definitions by dispatched routes.
     */
    protected array $resolvedDefinitions = [];

    /**
     * All of the after resolving callbacks by class type.
     */
    protected array $afterResolvingCallbacks = [];

    /**
     * All of the resolved callbacks by class type.
     */
    protected array $resolvedCallbacks = [];

    /**
     * Indicates if the resolvingCallbacks has been registered.
     */
    protected bool $resolvingCallbacksRegistered = false;

    public function __construct(
        protected ContainerInterface $container,
        protected NormalizerInterface $normalizer,
        protected MethodDefinitionCollectorInterface $methodDefinitionCollector,
        protected ClosureDefinitionCollectorInterface $closureDefinitionCollector
    ) {
    }

    /**
     * Register an "after resolving" callback for given class.
     */
    public function afterResolving(string $class, callable $callback): void
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            throw new InvalidArgumentException("Class '{$class}' does not exist");
        }

        if (! $this->resolvingCallbacksRegistered) {
            $this->resolvingCallbacksRegistered = true;
        }

        $this->afterResolvingCallbacks[$class][] = $callback;
    }

    /**
     * Call the "after resolving" callbacks for the given dependencies.
     */
    public function fireAfterResolvingCallbacks(array $dependencies, Dispatched $dispatched): void
    {
        if (! $this->resolvingCallbacksRegistered) {
            return;
        }

        foreach ($dependencies as $dependency) {
            if (! is_object($dependency)) {
                continue;
            }
            foreach ($this->getAfterResolvingCallbacks($dependency) as $callback) {
                $callback($dependency, $dispatched);
            }
        }
    }

    /**
     * Get the "after resolving" callbacks for the given class.
     */
    public function getAfterResolvingCallbacks(object $object): array
    {
        $className = get_class($object);
        if (isset($this->resolvedCallbacks[$className])) {
            return $this->resolvedCallbacks[$className];
        }

        $result = [];
        foreach ($this->afterResolvingCallbacks as $class => $callbacks) {
            if ($object instanceof $class) {
                $result = array_merge($result, $callbacks);
            }
        }

        return $this->resolvedCallbacks[$className] = $result;
    }

    /**
     * Parse the parameters of method definitions, and then bind the specified arguments or
     * get the value from DI container, combine to an argument array that should be injected
     * and return the array.
     */
    public function getMethodParameters(string $controller, string $action, array $arguments): array
    {
        $signature = "{$controller}::{$action}";
        return $this->getDependencies(
            $this->resolvedDefinitions[$signature] ?? $this->resolvedDefinitions[$signature] = $this->methodDefinitionCollector->getParameters($controller, $action),
            "{$controller}::{$action}",
            $arguments
        );
    }

    /**
     * Parse the parameters of closure definitions, and then bind the specified arguments or
     * get the value from DI container, combine to an argument array that should be injected
     * and return the array.
     */
    public function getClosureParameters(Closure $closure, array $arguments): array
    {
        $signature = spl_object_hash($closure);
        return $this->getDependencies(
            $this->resolvedDefinitions[$signature] ?? $this->resolvedDefinitions[$signature] = $this->closureDefinitionCollector->getParameters($closure),
            'Closure',
            $arguments
        );
    }

    /**
     * @param ReflectionType[] $definitions
     */
    protected function getDependencies(array $definitions, string $callableName, array $arguments): array
    {
        $dependencies = [];
        foreach ($definitions as $index => $definition) {
            if ($value = $arguments[$index] ?? $arguments[$definition->getMeta('name')] ?? null) {
                $dependencies[] = $this->normalizer->denormalize($value, $definition->getName());
            } elseif ($definition->getMeta('defaultValueAvailable')) {
                $dependencies[] = $definition->getMeta('defaultValue');
            } elseif ($this->container->has($name = $definition->getName())) {
                $dependencies[] = $this->container->get($name);
            } elseif ($definition->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new InvalidArgumentException("Parameter '{$definition->getMeta('name')}' "
                    . "of {$callableName} should not be null");
            }
        }

        return $dependencies;
    }
}
