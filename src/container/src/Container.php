<?php

declare(strict_types=1);

namespace Hypervel\Container;

use ArrayAccess;
use Closure;
use Hyperf\Di\Container as HyperfContainer;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use InvalidArgumentException;
use LogicException;
use TypeError;

class Container extends HyperfContainer implements ContainerContract, ArrayAccess
{
    /**
     * The container's method bindings.
     *
     * @var Closure[]
     */
    protected array $methodBindings = [];

    /**
     * The registered type aliases.
     *
     * @var string[]
     */
    protected array $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     *
     * @var array[]
     */
    protected array $abstractAliases = [];

    /**
     * The extension closures for services.
     *
     * @var array[]
     */
    protected array $extenders = [];

    /**
     * All of the registered rebound callbacks.
     *
     * @var array[]
     */
    protected array $reboundCallbacks = [];

    /**
     * All of the global before resolving callbacks.
     *
     * @var Closure[]
     */
    protected array $globalBeforeResolvingCallbacks = [];

    /**
     * All of the global resolving callbacks.
     *
     * @var Closure[]
     */
    protected array $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     *
     * @var Closure[]
     */
    protected array $globalAfterResolvingCallbacks = [];

    /**
     * All of the before resolving callbacks by class type.
     *
     * @var array[]
     */
    protected array $beforeResolvingCallbacks = [];

    /**
     * All of the resolving callbacks by class type.
     *
     * @var array[]
     */
    protected array $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     *
     * @var array[]
     */
    protected array $afterResolvingCallbacks = [];

    /**
     * Build an entry of the container by its name.
     * This method behave like get() except resolves the entry again every time.
     * For example if the entry is a class then a new instance will be created each time.
     * This method makes the container behave like a factory.
     *
     * @param string $name entry name or a class name
     * @param array $parameters Optional parameters to use to build the entry. Use this to force specific parameters
     *                          to specific values. Parameters not defined in this array will be resolved using
     *                          the container.
     * @throws \Hyperf\Di\Exception\NotFoundException no entry found for the given name
     * @throws InvalidArgumentException the name parameter must be of type string
     */
    public function make(string $name, array $parameters = []): mixed
    {
        if ($this->isAlias($name)) {
            $name = $this->getAlias($name);
        }

        // First we'll fire any event handlers which handle the "before" resolving of
        // specific types. This gives some hooks the chance to add various extends
        // calls to change the resolution of objects that they're interested in.
        $this->fireBeforeResolvingCallbacks($name, $parameters);

        $result = parent::make($name, $parameters);

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        foreach ($this->getExtenders($name) as $extender) {
            $result = $extender($result, $this);
        }

        $this->fireResolvingCallbacks($name, $result);

        return $result;
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id identifier of the entry to look for
     */
    public function get($id): mixed
    {
        if ($this->isAlias($id)) {
            $id = $this->getAlias($id);
        }

        return parent::get($id);
    }

    /**
     * Bind an arbitrary resolved entry to an identifier.
     * Useful for testing 'get'.
     * @param mixed $entry
     */
    public function set(string $name, $entry): void
    {
        if ($this->isAlias($name)) {
            $name = $this->getAlias($name);
        }

        parent::set($name, $entry);
    }

    /**
     * Unbind an arbitrary resolved entry.
     */
    public function unbind(string $name): void
    {
        if ($this->isAlias($name)) {
            $name = $this->getAlias($name);
        }

        parent::unbind($name);
    }

    /**
     * Remove an arbitrary binding.
     */
    public function remove(string $name): void
    {
        if ($this->isAlias($name)) {
            $name = $this->getAlias($name);
        }

        parent::unbind($name);

        /* @phpstan-ignore-next-line */
        $this->definitionSource->removeDefinition($name);

        unset($this->fetchedDefinitions[$name]);
    }

    /**
     * Determine if the given abstract type has been bound.
     */
    public function bound(string $abstract): bool
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return (bool) ($this->definitionSource->getDefinitions()[$abstract] ?? false);
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     * `has($name)` returning true does not mean that `get($name)` will not throw an exception.
     * It does however mean that `get($name)` will not throw a `NotFoundExceptionInterface`.
     *
     * @param mixed|string $id identifier of the entry to look for
     */
    public function has($id): bool
    {
        return $this->isAlias($id) || parent::has($id);
    }

    /**
     * Determine if the given abstract type has been resolved.
     */
    public function resolved(string $abstract): bool
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return isset($this->resolvedEntries[$abstract]);
    }

    /**
     * Determine if a given string is an alias.
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    /**
     * Register a binding with the container.
     *
     * @param null|Closure|string $concrete
     *
     * @throws TypeError
     */
    public function bind(string $abstract, mixed $concrete = null): void
    {
        $this->dropStaleInstances($abstract);

        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. After that, the concrete type to be registered as shared
        // without being forced to state their classes in both of the parameters.
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        if ($this->bound($abstract)) {
            $this->rebound($abstract);
        }

        $this->define($abstract, $concrete);
    }

    /**
     * Register a shared binding in the container.
     *
     * @temporary This is an alias for bind() until Laravel's container is ported.
     *            In Hyperf/Swoole, all bindings are singletons by default.
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete);
    }

    /**
     * Determine if the container has a method binding.
     */
    public function hasMethodBinding(string $method): bool
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     */
    public function bindMethod(array|string $method, Closure $callback): void
    {
        $this->methodBindings[$this->parseBindMethod($method)] = $callback;
    }

    /**
     * Get the method to be bound in class@method format.
     */
    protected function parseBindMethod(array|string $method): string
    {
        if (is_array($method)) {
            return $method[0] . '@' . $method[1];
        }

        return $method;
    }

    /**
     * Get the method binding for the given method.
     */
    public function callMethodBinding(string $method, mixed $instance): mixed
    {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param null|Closure|string $concrete
     */
    public function bindIf(string $abstract, mixed $concrete = null): void
    {
        if (! $this->bound($abstract)) {
            $this->bind($abstract, $concrete);
        }
    }

    /**
     * "Extend" an abstract type in the container.
     *
     * @throws InvalidArgumentException
     */
    public function extend(string $abstract, Closure $closure): void
    {
        $abstract = $this->getAlias($abstract);

        if ($this->resolved($abstract)) {
            $this->resolvedEntries[$abstract] = $closure($this->resolvedEntries[$abstract], $this);
            $this->rebound($abstract);
        }

        $this->extenders[$abstract][] = $closure;
    }

    /**
     * Register an existing instance as shared in the container.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->removeAbstractAlias($abstract);

        $isBound = $this->bound($abstract);

        unset($this->aliases[$abstract], $this->resolvedEntries[$abstract]);

        // Clear any cached resolved entry so the new instance is used

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container and it
        // can be updated with consuming classes that have gotten resolved here.
        if (is_object($instance)) {
            $instance = fn () => $instance;
        }

        // Define the new instance BEFORE firing rebound callbacks,
        // so callbacks receive the new instance, not the old one
        $this->define($abstract, $instance);

        if ($isBound) {
            $this->rebound($abstract);
        }

        return $instance;
    }

    /**
     * Remove an alias from the contextual binding alias cache.
     */
    protected function removeAbstractAlias(string $searched): void
    {
        if (! isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Alias a type to a different name.
     *
     * @throws LogicException
     */
    public function alias(string $abstract, string $alias): void
    {
        if ($alias === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }

        $this->aliases[$alias] = $abstract;

        $this->abstractAliases[$abstract][] = $alias;
    }

    /**
     * Bind a new callback to an abstract's rebind event.
     */
    public function rebinding(string $abstract, Closure $callback): mixed
    {
        $this->reboundCallbacks[$abstract = $this->getAlias($abstract)][] = $callback;

        if ($this->bound($abstract)) {
            return $this->get($abstract);
        }

        return null;
    }

    /**
     * Refresh an instance on the given target and method.
     */
    public function refresh(string $abstract, mixed $target, string $method): mixed
    {
        return $this->rebinding($abstract, function ($app, $instance) use ($target, $method) {
            $target->{$method}($instance);
        });
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     */
    protected function rebound(string $abstract): void
    {
        $instance = $this->get($abstract);

        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            $callback($this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     */
    protected function getReboundCallbacks(string $abstract): array
    {
        return $this->reboundCallbacks[$abstract] ?? [];
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string $callback
     * @param array<string, mixed> $parameters
     *
     * @throws BindingResolutionException
     */
    public function call($callback, array $parameters = [], ?string $defaultMethod = null): mixed
    {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * Get a closure to resolve the given type from the container.
     */
    public function factory(string $abstract): Closure
    {
        return fn () => $this->make($abstract);
    }

    /**
     * An alias function name for make().
     *
     * @param callable|string $abstract
     *
     * @throws \Hyperf\Di\Exception\InvalidDefinitionException
     */
    public function makeWith($abstract, array $parameters = []): mixed
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * Register a new before resolving callback for all types.
     *
     * @param Closure|string $abstract
     */
    public function beforeResolving($abstract, ?Closure $callback = null): void
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalBeforeResolvingCallbacks[] = $abstract;
        } else {
            $this->beforeResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new resolving callback.
     *
     * @param Closure|string $abstract
     */
    public function resolving($abstract, ?Closure $callback = null): void
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param Closure|string $abstract
     */
    public function afterResolving($abstract, ?Closure $callback = null): void
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Fire all of the before resolving callbacks.
     *
     * @param string $abstract
     * @param array $parameters
     */
    protected function fireBeforeResolvingCallbacks($abstract, $parameters = []): void
    {
        $this->fireBeforeCallbackArray($abstract, $parameters, $this->globalBeforeResolvingCallbacks);

        foreach ($this->beforeResolvingCallbacks as $type => $callbacks) {
            if ($type === $abstract || is_subclass_of($abstract, $type)) {
                $this->fireBeforeCallbackArray($abstract, $parameters, $callbacks);
            }
        }
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param string $abstract
     * @param array $parameters
     */
    protected function fireBeforeCallbackArray($abstract, $parameters, array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($abstract, $parameters, $this);
        }
    }

    /**
     * Fire all of the resolving callbacks.
     *
     * @param string $abstract
     * @param mixed $object
     */
    protected function fireResolvingCallbacks($abstract, $object): void
    {
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);

        $this->fireCallbackArray(
            $object,
            $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks)
        );

        $this->fireAfterResolvingCallbacks($abstract, $object);
    }

    /**
     * Fire all of the after resolving callbacks.
     *
     * @param string $abstract
     * @param mixed $object
     */
    protected function fireAfterResolvingCallbacks($abstract, $object): void
    {
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);

        $this->fireCallbackArray(
            $object,
            $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks)
        );
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param string $abstract
     * @param object $object
     * @param array<class-string, array> $callbacksPerType
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType): array
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param mixed $object
     */
    protected function fireCallbackArray($object, array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Get the container's bindings.
     */
    public function getBindings(): array
    {
        return $this->definitionSource->getDefinitions();
    }

    /**
     * Get the alias for an abstract if available.
     */
    public function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract])
            ? $this->getAlias($this->aliases[$abstract])
            : $abstract;
    }

    /**
     * Get the extender callbacks for a given type.
     */
    protected function getExtenders(string $abstract): array
    {
        return $this->extenders[$this->getAlias($abstract)] ?? [];
    }

    /**
     * Remove all of the extender callbacks for a given type.
     */
    public function forgetExtenders(string $abstract): void
    {
        unset($this->extenders[$this->getAlias($abstract)]);
    }

    /**
     * Drop all of the stale instances and aliases.
     */
    protected function dropStaleInstances(string $abstract): void
    {
        unset($this->resolvedEntries[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Remove a resolved instance from the instance cache.
     */
    public function forgetInstance(string $abstract): void
    {
        unset($this->resolvedEntries[$abstract]);
    }

    /**
     * Clear all of the instances from the container.
     */
    public function forgetInstances(): void
    {
        $this->resolvedEntries = [];
    }

    /**
     * Flush the container of all bindings and resolved instances.
     */
    public function flush(): void
    {
        $this->aliases = [];
        $this->resolvedEntries = [];
        $this->abstractAliases = [];
    }

    /**
     * Get the globally available instance of the container.
     */
    public static function getInstance(): ContainerContract
    {
        if (! ApplicationContext::hasContainer()) {
            ApplicationContext::setContainer(
                new static(new DefinitionSource([]))
            );
        }

        return ApplicationContext::getContainer(); // @phpstan-ignore return.type (we just set it with static() above)
    }

    /**
     * Set the shared instance of the container.
     */
    public static function setInstance(ContainerContract $container): ContainerContract
    {
        return ApplicationContext::setContainer($container); // @phpstan-ignore return.type
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->bound($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->define($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    /**
     * Dynamically access container services.
     */
    public function __get(string $key): mixed
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     */
    public function __set(string $key, mixed $value): void
    {
        $this[$key] = $value;
    }
}
