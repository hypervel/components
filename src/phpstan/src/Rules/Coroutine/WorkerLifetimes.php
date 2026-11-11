<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Closure;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Container\Attributes\Authenticated;
use Hypervel\Container\Attributes\Bind;
use Hypervel\Container\Attributes\BindWhen;
use Hypervel\Container\Attributes\Cache;
use Hypervel\Container\Attributes\Context;
use Hypervel\Container\Attributes\Database;
use Hypervel\Container\Attributes\RequestAttribute;
use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Container\Attributes\Scoped;
use Hypervel\Container\Attributes\Singleton;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Contracts\Container\ExecutionScopedAttribute;
use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Routing\MiddlewareNameResolver;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Support\Str;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionAttribute;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

use function array_any;
use function array_filter;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function str_contains;
use function strtolower;

/**
 * The worker lifetimes of one analysis: which classes the booted container shares across requests,
 * which methods handle a request or run a job, and which constructor parameters it fills with fresh values.
 *
 * Roots come from the booted routes, middleware, listeners and Bus handlers, and from the collected
 * resolutions, singletons and jobs. Each class they build is walked the way the container resolves it.
 * Classes are read through PHPStan reflection and factories are never called: resolving or loading an
 * analyzed class at runtime would run its code.
 *
 * @phpstan-import-type HelperCall from WorkerStateHelper
 */
class WorkerLifetimes
{
    private const string SHARED = 'shared';

    private const string SCOPED = 'scoped';

    private const string FRESH = 'fresh';

    private const string DEFAULT = 'default';

    private const string UNKNOWN = 'unknown';

    /** @var array<string, array{concrete: Closure, shared: bool}> */
    private array $bindings;

    /** @var array<string, mixed> */
    private array $instances;

    /** @var array<string, true> */
    private array $scopedInstances;

    /** @var array<string, array<string, mixed>> */
    private array $contextual;

    /** @var array<string, list<string>> */
    private array $abstractAliases;

    /** @var array<string, true> lowercase command classes the Bus maps to handlers */
    private array $mappedCommands = [];

    /** @var array<string, array{string, null|string, bool}> abstract and implicit flag => [lifetime, class, whether the container passes the constructor arguments] */
    private array $lifetimes = [];

    /** @var array<string, true> */
    private array $built = [];

    /** @var array<string, true> the worker-shared classes */
    private array $receivers = [];

    /** @var null|array<string, true> lowercase names of the worker-shared classes and their parents */
    private ?array $sharedClasses = null;

    /** @var array<string, array{string, bool}> lowercase "class::method" => the operation it handles and whether a shared instance handles it */
    private array $contexts = [];

    /** @var list<array{string, string, string, string, bool}> the receiver, declaring class, method, operation and whether the receiver is shared, of each context still to propagate */
    private array $pendingContexts = [];

    /** @var array<string, true> lowercase "class::method::parameter" => the global configuration repository is injected */
    private array $configParameters = [];

    /** @var array<string, bool> lowercase "class::parameter" => whether every shared instance gets its default or a fresh value */
    private array $freshParameters = [];

    /** @var array<string, true> the classes whose declarations the lifetimes depend on */
    private array $classes = [];

    /**
     * Discover the worker lifetimes of the booted application and the collected roots.
     *
     * @param list<string> $abstracts the abstracts resolved without parameters
     * @param list<string> $singletons the classes declaring #[Singleton]
     * @param list<string> $queued the concrete queued classes
     * @param list<HelperCall> $helperCalls the direct calls methods make on their own instance
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private Container $container,
        array $abstracts,
        array $singletons,
        array $queued,
        array $helperCalls,
    ) {
        $this->bindings = $container->getBindings();
        [$this->instances, $this->scopedInstances, $this->contextual, $this->abstractAliases] = (fn (): array => [
            $this->instances,
            $this->scopedInstances,
            $this->contextual,
            $this->abstractAliases,
        ])->call($container);

        $this->discoverBusHandlers();
        $this->discoverHttpRoots();
        $this->discoverListeners();

        foreach ([...array_keys($this->bindings), ...array_keys($this->instances), ...$abstracts, ...$singletons] as $abstract) {
            $this->make($abstract, null);
        }

        foreach ($queued as $class) {
            $this->discoverJob($class);
        }

        // After every build, so whether a job instance is shared reflects every registration.
        foreach ($queued as $class) {
            $job = $this->getClass($class);

            if ($job === null) {
                continue;
            }

            foreach (WorkerStateHelper::JOB_METHODS as $method) {
                if ($job->hasNativeMethod($method)) {
                    $declaringClass = $job->getNativeMethod($method)->getDeclaringClass()->getName();
                    $this->addContext($job->getName(), $declaringClass, $method, 'job', isset($this->receivers[$job->getName()]));
                }
            }
        }

        $this->propagateContexts($helperCalls);
    }

    /**
     * Determine whether the container shares the class, or a subclass of it, across requests.
     */
    public function isShared(string $class): bool
    {
        if ($this->sharedClasses === null) {
            $this->sharedClasses = [];

            foreach (array_keys($this->receivers) as $receiver) {
                $this->sharedClasses[strtolower($receiver)] = true;

                foreach ($this->reflectionProvider->getClass($receiver)->getParentClassesNames() as $parent) {
                    $this->sharedClasses[strtolower($parent)] = true;
                }
            }
        }

        return isset($this->sharedClasses[strtolower($class)]);
    }

    /**
     * Get the operation a booted middleware or controller action method handles, or null.
     */
    public function getContext(string $class, string $method): ?string
    {
        return $this->contexts[strtolower($class . '::' . $method)][0] ?? null;
    }

    /**
     * Determine whether a worker-shared instance handles the booted middleware or controller action
     * method, rather than only scoped or fresh ones.
     */
    public function isContextShared(string $class, string $method): bool
    {
        return $this->contexts[strtolower($class . '::' . $method)][1] ?? false;
    }

    /**
     * Determine whether the container injects the global configuration repository into the parameter
     * of a booted controller action or a job's handler.
     */
    public function isConfigParameter(string $class, string $method, string $parameter): bool
    {
        return isset($this->configParameters[strtolower($class . '::' . $method . '::' . $parameter)]);
    }

    /**
     * Determine whether the container name resolves the global configuration repository, which the
     * framework registers as "config" with its contract and class as aliases.
     */
    public function isGlobalConfig(string $abstract): bool
    {
        return $this->container->getAlias($abstract) === 'config';
    }

    /**
     * Determine whether every shared instance built with the class's constructor gets the parameter's
     * default or a fresh value from the container.
     */
    public function isFreshParameter(string $class, string $parameter): bool
    {
        return $this->freshParameters[strtolower($class . '::' . $parameter)] ?? false;
    }

    /**
     * Determine whether the class is a job whose handler the container calls with injected parameters.
     */
    public function isJob(string $class): bool
    {
        $reflection = $this->getClass($class);

        return $reflection !== null
            && ! isset($this->mappedCommands[strtolower($class)])
            && isset($reflection->getTraits(true)[Dispatchable::class]);
    }

    /**
     * Get the classes whose declarations the lifetimes were read from.
     *
     * @return list<string>
     */
    public function getClasses(): array
    {
        return array_keys($this->classes);
    }

    /**
     * Discover the Bus command handlers, which are resolved from the container and called directly
     * with their commands.
     */
    private function discoverBusHandlers(): void
    {
        if (! $this->container->bound(BusDispatcherContract::class)) {
            return;
        }

        $dispatcher = $this->container->make(BusDispatcherContract::class);

        if (! $dispatcher instanceof BusDispatcher) {
            return;
        }

        foreach ((fn (): array => $this->handlers)->call($dispatcher) as $command => $handler) {
            $this->mappedCommands[strtolower($command)] = true;

            if (is_string($handler)) {
                $this->make($handler, null);
            }
        }
    }

    /**
     * Discover the controllers, actions and middleware of the booted routes and HTTP kernel.
     */
    private function discoverHttpRoots(): void
    {
        $router = $this->container->bound('router') ? $this->container->make('router') : null;
        $kernel = $this->container->bound(Kernel::class) ? $this->container->make(Kernel::class) : null;
        $middleware = [];
        $aliases = [];
        $groups = [];

        if ($kernel instanceof Kernel) {
            $middleware = $kernel->getGlobalMiddleware();
            $aliases = $kernel->getMiddlewareAliases();
            $groups = $kernel->getMiddlewareGroups();
        }

        if ($router instanceof Router) {
            $aliases = [...$aliases, ...$router->getMiddleware()];
            $groups = [...$groups, ...$router->getMiddlewareGroups()];

            foreach ($router->getRoutes()->getRoutes() as $route) {
                foreach ((array) ($route->getAction('middleware') ?? []) as $name) {
                    $middleware[] = $name;
                }

                $this->discoverRoute($route, $router);
            }
        }

        // Every alias and group can be named by a route the analysis does not see, such as a
        // controller's own middleware.
        $names = array_unique(array_filter([...$middleware, ...array_values($aliases), ...array_keys($groups)], is_string(...)));
        $classes = [];

        foreach ($names as $name) {
            $resolved = MiddlewareNameResolver::resolve($name, $aliases, $groups);

            foreach (is_array($resolved) ? $resolved : [$resolved] as $entry) {
                if (is_string($entry)) {
                    $classes[explode(':', $entry, 2)[0]] = true;
                }
            }
        }

        foreach (array_keys($classes) as $class) {
            $reflection = $this->getClass($class);

            if ($reflection === null) {
                continue;
            }

            [$shared, $receiver] = $this->makeHandler($reflection);

            foreach (['handle', 'terminate'] as $method) {
                if ($receiver->hasNativeMethod($method)) {
                    $this->addContext($receiver->getName(), $receiver->getNativeMethod($method)->getDeclaringClass()->getName(), $method, 'middleware', $shared);
                }
            }
        }
    }

    /**
     * Discover a route's controller and the dependencies its action receives.
     */
    private function discoverRoute(Route $route, Router $router): void
    {
        $uses = $route->getAction('uses');

        $actionClass = null;

        if ($uses instanceof Closure) {
            $parameters = (new ReflectionFunction($uses))->getParameters();
        } elseif (is_string($uses)) {
            [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
            $reflection = $this->getClass($class);

            if ($reflection === null) {
                return;
            }

            [$shared, $receiver] = $this->makeHandler($reflection);

            if (! $receiver->hasNativeMethod($method)) {
                return;
            }

            $actionClass = $receiver->getNativeMethod($method)->getDeclaringClass()->getName();
            $this->addContext($receiver->getName(), $actionClass, $method, 'action', $shared);
            $parameters = $receiver->getNativeReflection()->getMethod($method)->getParameters();
        } else {
            return;
        }

        $names = $route->parameterNames();

        // An explicit route binder can return an object of any class.
        $bound = array_any($names, static fn (string $name): bool => $router->getBindingCallback($name) !== null);

        foreach ($parameters as $parameter) {
            $class = $this->getParameterClass($parameter);

            // ResolvesRouteDependencies::transformDependency() skips a class a route binding may supply,
            // and never resolves a parameter with a default. ImplicitRouteBinding only binds a
            // UrlRoutable parameter named like a route parameter, or its snake case.
            if ($class === null
                || $this->getContextualAttribute($parameter) !== null
                || $parameter->isDefaultValueAvailable()
                || $bound
                || ($this->getClass($class)?->is(UrlRoutable::class) === true
                    && array_intersect([$parameter->getName(), Str::snake($parameter->getName())], $names) !== [])) {
                continue;
            }

            if ($actionClass !== null && $this->isGlobalConfig($class)) {
                $this->configParameters[strtolower($actionClass . '::' . $method . '::' . $parameter->getName())] = true;
            }

            $this->make($class, null);
        }
    }

    /**
     * Resolve a middleware or controller class as the container builds it to handle a request, and
     * return whether the instance is shared and its class, which may be a bound subclass.
     *
     * @return array{bool, ClassReflection}
     */
    private function makeHandler(ClassReflection $class): array
    {
        [$lifetime, $built] = $this->make($class->getName(), null);

        return [$lifetime === self::SHARED, ($built === null ? null : $this->getClass($built)) ?? $class];
    }

    /**
     * Record the operation a method handles for a receiver, and whether a worker-shared instance
     * handles it, then queue it so the helpers it calls on the receiver handle the operation too.
     */
    private function addContext(string $receiver, string $declaringClass, string $method, string $context, bool $shared): void
    {
        $key = strtolower($declaringClass . '::' . $method);

        $this->contexts[$key] = [$context, $shared || ($this->contexts[$key][1] ?? false)];
        $this->pendingContexts[] = [$receiver, $declaringClass, $method, $context, $shared];
    }

    /**
     * Give each helper a context method calls on its receiver that context, along every chain of
     * direct calls.
     *
     * @param list<HelperCall> $helperCalls
     */
    private function propagateContexts(array $helperCalls): void
    {
        $callees = [];

        foreach ($helperCalls as $call) {
            $callees[strtolower($call['class'] . '::' . $call['method'])][strtolower($call['callee'])] = $call['callee'];
        }

        // Whether each receiver's method was visited as shared: a shared visit covers a later
        // unshared one, but not the other way round.
        $visited = [];

        while (($pending = array_pop($this->pendingContexts)) !== null) {
            [$receiver, $declaringClass, $method, $context, $shared] = $pending;
            $key = strtolower($receiver . "\0" . $declaringClass . '::' . $method);

            if (array_key_exists($key, $visited) && ($visited[$key] || ! $shared)) {
                continue;
            }

            $visited[$key] = $shared;

            foreach ($callees[strtolower($declaringClass . '::' . $method)] ?? [] as $callee) {
                $helper = $this->resolveHelper($receiver, $declaringClass, $callee);

                if ($helper !== null) {
                    $this->addContext($receiver, $helper->getDeclaringClass()->getName(), $helper->getName(), $context, $shared);
                }
            }
        }
    }

    /**
     * Get the instance method that a call on $this in the caller's class runs for the receiver, or
     * null when the call does not reach one.
     *
     * The caller's own private method runs whatever the receiver's class, as PHP binds private calls
     * to the calling scope; any other call runs the receiver's implementation.
     */
    private function resolveHelper(string $receiver, string $callerClass, string $name): ?ExtendedMethodReflection
    {
        $caller = $this->getClass($callerClass);
        $method = null;

        if ($caller !== null && $caller->hasNativeMethod($name)) {
            $own = $caller->getNativeMethod($name);
            $method = $own->isPrivate() && $own->getDeclaringClass()->getName() === $caller->getName() ? $own : null;
        }

        if ($method === null) {
            $class = $this->getClass($receiver);
            $method = $class !== null && $class->hasNativeMethod($name) ? $class->getNativeMethod($name) : null;

            // Another class's private method is out of the caller's reach.
            if ($method === null || $method->isPrivate()) {
                return null;
            }
        }

        return $method->isStatic() || strtolower($method->getName()) === '__construct' ? null : $method;
    }

    /**
     * Discover the class-string event listeners, which the dispatcher resolves from the container.
     */
    private function discoverListeners(): void
    {
        $events = $this->container->bound('events') ? $this->container->make('events') : null;

        if (! $events instanceof EventDispatcher) {
            return;
        }

        $listeners = [...$events->getRawListeners(), ...(fn (): array => $this->wildcards)->call($events)];

        foreach (array_merge(...array_values($listeners)) as $listener) {
            $class = match (true) {
                is_string($listener) => explode('@', $listener, 2)[0],
                is_array($listener) && is_string($listener[0] ?? null) => $listener[0],
                default => null,
            };

            if ($class !== null && $this->getClass($class) !== null) {
                $this->make($class, null);
            }
        }
    }

    /**
     * Discover the dependencies the container injects into a job's handler.
     *
     * Only a class using the Bus Dispatchable trait is known to be a job: queued listeners and mapped
     * commands are called directly, without injection.
     */
    private function discoverJob(string $class): void
    {
        $reflection = $this->getClass($class);

        if ($reflection === null || ! $this->isJob($class)) {
            return;
        }

        $method = $reflection->hasNativeMethod('handle') ? 'handle' : '__invoke';

        if (! $reflection->hasNativeMethod($method)) {
            return;
        }

        $declaringClass = $reflection->getNativeMethod($method)->getDeclaringClass()->getName();

        foreach ($reflection->getNativeReflection()->getMethod($method)->getParameters() as $parameter) {
            $dependency = $this->getParameterClass($parameter);

            // BoundMethod uses a default whenever the class is unbound, without checking contextual bindings.
            if ($dependency === null
                || $this->getContextualAttribute($parameter) !== null
                || (! $parameter->isVariadic() && $parameter->isDefaultValueAvailable() && ! $this->container->bound($dependency))) {
                continue;
            }

            // A contextual binding for the job may replace the repository with another one.
            $concrete = $this->getContextualConcrete($reflection->getName(), $this->container->getAlias($dependency));

            if ($this->isGlobalConfig($dependency) && ($concrete === null || (is_string($concrete) && $this->isGlobalConfig($concrete)))) {
                $this->configParameters[strtolower($declaringClass . '::' . $method . '::' . $parameter->getName())] = true;
            }

            $this->make($dependency, $reflection->getName());
        }
    }

    /**
     * Resolve the abstract as make() does while building the consumer, walking the classes it
     * builds, and return the lifetime of the result and the class it builds.
     *
     * @return array{string, null|string}
     */
    private function make(string $abstract, ?string $consumer): array
    {
        $abstract = $this->container->getAlias($abstract);

        if ($consumer !== null && ($concrete = $this->getContextualConcrete($consumer, $abstract)) !== null) {
            // A contextual build is never cached; a class name is resolved on its own, a closure is unknown.
            return is_string($concrete) ? $this->make($concrete, null) : [self::UNKNOWN, null];
        }

        [$lifetime, $class, $knownArguments] = $this->getLifetime($abstract, true);

        if ($class !== null) {
            $this->build($class, $lifetime, $knownArguments);
        }

        return [$lifetime, $class];
    }

    /**
     * Get the lifetime of the abstract's resolution, the class it builds and whether the container
     * passes that class's constructor arguments.
     *
     * Without implicit lifetimes the abstract is a binding's concrete, which only an explicit
     * lifetime separates from its binding.
     *
     * @return array{string, null|string, bool}
     */
    private function getLifetime(string $abstract, bool $implicit): array
    {
        $key = $abstract . ($implicit ? '' : "\0concrete");

        if (isset($this->lifetimes[$key])) {
            return $this->lifetimes[$key];
        }

        // Bindings can be cyclic; the container fails for those, so the cycle is unknown.
        $this->lifetimes[$key] = [self::UNKNOWN, null, false];

        return $this->lifetimes[$key] = $this->computeLifetime($abstract, $implicit);
    }

    /**
     * Compute the lifetime of the abstract's resolution.
     *
     * @return array{string, null|string, bool}
     */
    private function computeLifetime(string $abstract, bool $implicit): array
    {
        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding === null ? null : $this->getBindingConcrete($binding['concrete']);

        if (array_key_exists($abstract, $this->instances)) {
            $instance = $this->instances[$abstract];

            // An instance its binding built got its constructor arguments from the container.
            return is_object($instance)
                ? [self::SHARED, $instance::class, $concrete !== null && strtolower($concrete) === strtolower($instance::class)]
                : [self::UNKNOWN, null, false];
        }

        if ($binding !== null) {
            if ($concrete === null) {
                return [self::UNKNOWN, null, false];
            }

            $lifetime = match (true) {
                isset($this->scopedInstances[$abstract]) => self::SCOPED,
                $binding['shared'] => self::SHARED,
                default => self::FRESH,
            };

            if ($concrete === $abstract) {
                $class = $this->getBuildableClass($concrete);

                return $class === null ? [self::UNKNOWN, null, false] : [$lifetime, $class->getName(), ! $class->is(SelfBuilding::class)];
            }

            [$concreteLifetime, $class, $knownArguments] = $this->getLifetime($this->container->getAlias($concrete), false);

            if ($concreteLifetime === self::UNKNOWN) {
                return [self::UNKNOWN, null, false];
            }

            return [$lifetime === self::FRESH ? $concreteLifetime : $lifetime, $class, $knownArguments];
        }

        $class = $this->getBuildableClass($abstract);

        if ($class === null) {
            return [self::UNKNOWN, null, false];
        }

        $native = $class->getNativeReflection();
        $selfBuilding = $class->is(SelfBuilding::class);

        return match (true) {
            $native->getAttributes(Singleton::class) !== [] => [self::SHARED, $class->getName(), ! $selfBuilding],
            $native->getAttributes(Scoped::class) !== [] => [self::SCOPED, $class->getName(), ! $selfBuilding],
            $selfBuilding || $class->is(Transient::class) => [self::FRESH, $class->getName(), ! $selfBuilding],
            default => match ($this->isExecutionScoped($class)) {
                true => [self::SCOPED, $class->getName(), true],
                null => [self::UNKNOWN, null, false],
                false => [$implicit ? self::SHARED : self::FRESH, $class->getName(), true],
            },
        };
    }

    /**
     * Record a built class and walk the constructor dependencies the container resolves for it.
     *
     * A SelfBuilding class or an instance registered at boot gets its arguments elsewhere, so its
     * dependencies are not walked and none of its parameters is known to be fresh.
     */
    private function build(string $class, string $lifetime, bool $knownArguments): void
    {
        $shared = $lifetime === self::SHARED;
        $key = strtolower($class) . "\0" . ($shared ? 'shared' : 'other') . "\0" . ($knownArguments ? 'known' : 'unknown');

        if ($shared) {
            $this->receivers[$class] = true;
        }

        if (isset($this->built[$key])) {
            return;
        }

        $this->built[$key] = true;
        $constructor = $this->getClass($class)?->getNativeReflection()->getConstructor();

        if ($constructor === null) {
            return;
        }

        $declaringClass = $constructor->getDeclaringClass()->getName();

        foreach ($constructor->getParameters() as $parameter) {
            $outcome = $knownArguments ? $this->resolveConstructorParameter($parameter, $class) : self::UNKNOWN;

            if ($shared) {
                $parameterKey = strtolower($declaringClass . '::' . $parameter->getName());
                $this->freshParameters[$parameterKey] = ($this->freshParameters[$parameterKey] ?? true)
                    && in_array($outcome, [self::DEFAULT, self::FRESH], true);
            }
        }
    }

    /**
     * Resolve a constructor parameter as Container::resolveRecipeParameters() does for the class being
     * built, and return what it receives.
     */
    private function resolveConstructorParameter(ReflectionParameter $parameter, string $consumer): string
    {
        if ($this->getContextualAttribute($parameter) !== null) {
            return self::UNKNOWN;
        }

        $class = $this->getParameterClass($parameter);

        if ($class === null) {
            return $parameter->isDefaultValueAvailable() && ! isset($this->contextual[$consumer]['$' . $parameter->getName()])
                ? self::DEFAULT
                : self::UNKNOWN;
        }

        if ($parameter->isDefaultValueAvailable()
            && ! $this->container->bound($class)
            && ! isset($this->contextual[$consumer][$class])) {
            return self::DEFAULT;
        }

        if ($parameter->isVariadic()) {
            $concrete = $this->getContextualConcrete($consumer, $this->container->getAlias($class));

            if (is_array($concrete)) {
                foreach ($concrete as $abstract) {
                    if (is_string($abstract)) {
                        $this->make($abstract, $consumer);
                    }
                }

                return self::UNKNOWN;
            }
        }

        return $this->make($class, $consumer)[0];
    }

    /**
     * Get the contextual binding the consumer has for the abstract, or one of its aliases.
     */
    private function getContextualConcrete(string $consumer, string $abstract): mixed
    {
        $bindings = $this->contextual[$consumer] ?? [];

        foreach ([$abstract, ...$this->abstractAliases[$abstract] ?? []] as $name) {
            if (isset($bindings[$name])) {
                return $bindings[$name];
            }
        }

        return null;
    }

    /**
     * Get the class a binding's closure builds when it was registered with a class name.
     */
    private function getBindingConcrete(Closure $closure): ?string
    {
        $reflection = new ReflectionFunction($closure);
        $concrete = $reflection->getStaticVariables()['concrete'] ?? null;

        // Container::getClosure() wraps a class-name concrete; any other closure is a factory.
        return $reflection->getClosureScopeClass()?->getName() === Container::class && is_string($concrete)
            ? $concrete
            : null;
    }

    /**
     * Get the class the container can build for the name, or null when it is unknown, not
     * instantiable, or bound by its own Bind or BindWhen attributes.
     */
    private function getBuildableClass(string $name): ?ClassReflection
    {
        $class = $this->getClass($name);

        if ($class === null || ! $class->isClass() || $class->isAbstract()) {
            return null;
        }

        $native = $class->getNativeReflection();

        return $native->getAttributes(Bind::class) === [] && $native->getAttributes(BindWhen::class) === [] ? $class : null;
    }

    /**
     * Determine whether a constructor attribute gives the class a lifetime scoped to the execution, or
     * null when an attribute decides it at runtime in a way the analysis cannot read.
     *
     * Container::computeBuildRecipe() constructs each attribute and asks it. The framework's
     * attributes decide from their class or constant arguments, which are read without constructing.
     */
    private function isExecutionScoped(ClassReflection $class): ?bool
    {
        $scoped = false;

        foreach ($class->getNativeReflection()->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = $this->getContextualAttribute($parameter);
            $attributeClass = $attribute === null ? null : $this->getClass($attribute->getName());

            if ($attributeClass === null || ! $attributeClass->is(ExecutionScopedAttribute::class)) {
                continue;
            }

            $decidedBy = $attributeClass->getNativeMethod('isExecutionScoped')->getDeclaringClass()->getName();
            $arguments = $decidedBy === Cache::class ? $attribute->getArguments() : [];
            $memo = $arguments['memo'] ?? $arguments[1] ?? false;

            $decision = match ($decidedBy) {
                Authenticated::class, Context::class, Database::class, RequestAttribute::class, RouteParameter::class => true,
                Cache::class => is_bool($memo) ? $memo : null,
                default => null,
            };

            if ($decision === true) {
                return true;
            }

            $scoped = $decision === null ? null : $scoped;
        }

        return $scoped;
    }

    /**
     * Get the parameter's first contextual attribute, which supplies its value.
     */
    private function getContextualAttribute(ReflectionParameter $parameter): ?ReflectionAttribute
    {
        foreach ($parameter->getAttributes() as $attribute) {
            if ($this->getClass($attribute->getName())?->is(ContextualAttribute::class) === true) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Get the class a parameter's type names, as Container\Util::getParameterClassName() does.
     */
    private function getParameterClass(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $declaringClass = $parameter->getDeclaringClass();

        return match ($type->getName()) {
            'self' => $declaringClass?->getName(),
            'parent' => ($declaringClass?->getParentClass() ?: null)?->getName(),
            default => $type->getName(),
        };
    }

    /**
     * Get the PHPStan reflection of the class, recording it as a dependency, or null when it is unknown.
     */
    private function getClass(string $name): ?ClassReflection
    {
        if (! $this->reflectionProvider->hasClass($name)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($name);
        $this->classes[$class->getName()] = true;

        return $class;
    }
}
