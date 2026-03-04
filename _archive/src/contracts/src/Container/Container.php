<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Container;

use Closure;
use Hyperf\Contract\ContainerInterface as HyperfContainerInterface;
use InvalidArgumentException;
use LogicException;
use TypeError;

interface Container extends HyperfContainerInterface
{
    /**
     * Unbind an arbitrary resolved entry.
     */
    public function unbind(string $name): void;

    /**
     * Determine if the given abstract type has been bound.
     */
    public function bound(string $abstract): bool;

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     * `has($name)` returning true does not mean that `get($name)` will not throw an exception.
     * It does however mean that `get($name)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param mixed|string $id identifier of the entry to look for
     */
    public function has($id): bool;

    /**
     * Determine if the given abstract type has been resolved.
     */
    public function resolved(string $abstract): bool;

    /**
     * Determine if a given string is an alias.
     */
    public function isAlias(string $name): bool;

    /**
     * Register a binding with the container.
     *
     * @param null|Closure|string $concrete
     *
     * @throws TypeError
     */
    public function bind(string $abstract, mixed $concrete = null): void;

    /**
     * Determine if the container has a method binding.
     */
    public function hasMethodBinding(string $method): bool;

    /**
     * Bind a callback to resolve with Container::call.
     */
    public function bindMethod(array|string $method, Closure $callback): void;

    /**
     * Get the method binding for the given method.
     */
    public function callMethodBinding(string $method, mixed $instance): mixed;

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param null|Closure|string $concrete
     */
    public function bindIf(string $abstract, mixed $concrete = null): void;

    /**
     * "Extend" an abstract type in the container.
     *
     * @throws InvalidArgumentException
     */
    public function extend(string $abstract, Closure $closure): void;

    /**
     * Register an existing instance as shared in the container.
     */
    public function instance(string $abstract, mixed $instance): mixed;

    /**
     * Alias a type to a different name.
     *
     * @throws LogicException
     */
    public function alias(string $abstract, string $alias): void;

    /**
     * Bind a new callback to an abstract's rebind event.
     */
    public function rebinding(string $abstract, Closure $callback): mixed;

    /**
     * Refresh an instance on the given target and method.
     */
    public function refresh(string $abstract, mixed $target, string $method): mixed;

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string $callback
     * @param array<string, mixed> $parameters
     *
     * @throws InvalidArgumentException
     */
    public function call($callback, array $parameters = [], ?string $defaultMethod = null): mixed;

    /**
     * Get a closure to resolve the given type from the container.
     */
    public function factory(string $abstract): Closure;

    /**
     * An alias function name for make().
     *
     * @param callable|string $abstract
     *
     * @throws \Hyperf\Di\Exception\NotFoundException
     */
    public function makeWith($abstract, array $parameters = []): mixed;

    /**
     * Register a new before resolving callback for all types.
     *
     * @param Closure|string $abstract
     */
    public function beforeResolving($abstract, ?Closure $callback = null): void;

    /**
     * Register a new resolving callback.
     *
     * @param Closure|string $abstract
     */
    public function resolving($abstract, ?Closure $callback = null): void;

    /**
     * Register a new after resolving callback for all types.
     *
     * @param Closure|string $abstract
     */
    public function afterResolving($abstract, ?Closure $callback = null): void;

    /**
     * Get the container's bindings.
     */
    public function getBindings(): array;

    /**
     * Get the alias for an abstract if available.
     */
    public function getAlias(string $abstract): string;

    /**
     * Remove all of the extender callbacks for a given type.
     */
    public function forgetExtenders(string $abstract): void;

    /**
     * Remove a resolved instance from the instance cache.
     */
    public function forgetInstance(string $abstract): void;

    /**
     * Clear all of the instances from the container.
     */
    public function forgetInstances(): void;

    /**
     * Flush the container of all bindings and resolved instances.
     */
    public function flush(): void;

    /**
     * Get the globally available instance of the container.
     */
    public static function getInstance(): Container;

    /**
     * Set the shared instance of the container.
     */
    public static function setInstance(Container $container): Container;
}
