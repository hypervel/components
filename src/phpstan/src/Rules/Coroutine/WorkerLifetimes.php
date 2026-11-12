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
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Core\Events\OnClose;
use Hypervel\Core\Events\OnConnect;
use Hypervel\Core\Events\OnFinish;
use Hypervel\Core\Events\OnPacket;
use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Core\Events\OnReceive;
use Hypervel\Core\Events\OnTask;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\HttpServer\Events\RequestCanceled;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Events\JobTimedOut;
use Hypervel\Routing\Events\PreparingResponse;
use Hypervel\Routing\Events\ResponsePrepared;
use Hypervel\Routing\Events\RouteMatched;
use Hypervel\Routing\Events\Routing;
use Hypervel\Routing\MiddlewareNameResolver;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Support\Str;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use ReflectionAttribute;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

use function array_all;
use function array_any;
use function array_filter;
use function array_intersect;
use function array_key_exists;
use function array_keys;
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
use function str_starts_with;
use function strtolower;

/**
 * The worker lifetimes of one analysis: which classes the booted container shares across requests,
 * which methods handle a request or run a job, and which parameters it fills with fresh or shared values.
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

    /**
     * The framework events dispatched for one request, job or server operation, with the context
     * their listeners run in.
     */
    private const array EVENT_CONTEXTS = [
        RequestReceived::class => 'request',
        RequestHandled::class => 'request',
        RequestTerminated::class => 'request',
        ResponseSent::class => 'request',
        RequestCanceled::class => 'request',
        Routing::class => 'request',
        RouteMatched::class => 'request',
        PreparingResponse::class => 'request',
        ResponsePrepared::class => 'request',
        JobProcessing::class => 'job',
        JobProcessed::class => 'job',
        JobFailed::class => 'job',
        JobExceptionOccurred::class => 'job',
        JobAttempted::class => 'job',
        JobReleasedAfterException::class => 'job',
        JobTimedOut::class => 'job',
        OnReceive::class => 'operation',
        OnPacket::class => 'operation',
        OnTask::class => 'operation',
        OnFinish::class => 'operation',
        OnPipeMessage::class => 'operation',
        OnConnect::class => 'operation',
        OnClose::class => 'operation',
    ];

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

    /** @var array<string, array<string, true>> lowercase "class::method" => the classes of the receivers it handles an operation for */
    private array $contextReceivers = [];

    /** @var list<array{string, string, string, string, bool}> the receiver, declaring class, method, operation and whether the receiver is shared, of each context still to propagate */
    private array $pendingContexts = [];

    /** @var array<string, null|string> lowercase "class::method::parameter" => the canonical abstract the container injects on every call it makes, or null when that is not known */
    private array $injectedParameters = [];

    /** @var array<string, bool> lowercase "class::parameter" => whether every shared instance gets its default or a fresh value */
    private array $freshParameters = [];

    /** @var array<string, true> lowercase names of the queued classes the dispatcher resolves from the container as listeners */
    private array $queuedListeners = [];

    /** @var array<string, true> the classes whose declarations the lifetimes depend on */
    private array $classes = [];

    /**
     * Discover the worker lifetimes of the booted application and the collected roots.
     *
     * @param list<string> $abstracts the abstracts resolved without parameters
     * @param list<string> $singletons the classes declaring #[Singleton]
     * @param list<string> $queued the concrete queued classes
     * @param list<HelperCall> $helperCalls the direct calls methods make on their own instance
     * @param array<string, list<string>> $observers the #[ObservedBy] observer classes, each with the model events it can handle
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private Container $container,
        array $abstracts,
        array $singletons,
        array $queued,
        array $helperCalls,
        array $observers,
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
        $this->discoverObservers($observers);

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
     * Determine whether resolving the abstract without parameters returns the instance the container
     * shares for the worker.
     *
     * A bind() factory may return a shared object, so its resolution is unknown.
     */
    public function sharesAbstract(string $abstract): TrinaryLogic
    {
        return match ($this->getLifetime($this->container->getAlias($abstract), true)[0]) {
            self::SHARED => TrinaryLogic::createYes(),
            self::UNKNOWN => TrinaryLogic::createMaybe(),
            default => TrinaryLogic::createNo(),
        };
    }

    /**
     * Get the operation a booted middleware, controller action, job, listener or observer method
     * handles, or null.
     */
    public function getContext(string $class, string $method): ?string
    {
        return $this->contexts[strtolower($class . '::' . $method)][0] ?? null;
    }

    /**
     * Determine whether a worker-shared instance handles the booted handler method, rather than only
     * scoped or fresh ones.
     */
    public function isContextShared(string $class, string $method): bool
    {
        return $this->contexts[strtolower($class . '::' . $method)][1] ?? false;
    }

    /**
     * Determine whether the container injects the global configuration repository into the parameter
     * of a booted controller action, a job's handler or a constructor it runs.
     */
    public function isConfigParameter(string $class, string $method, string $parameter): bool
    {
        $abstract = $this->injectedParameters[strtolower($class . '::' . $method . '::' . $parameter)] ?? null;

        return $abstract !== null && $this->isGlobalConfig($abstract);
    }

    /**
     * Determine whether the container injects the instance it shares for the worker into the parameter
     * of a booted controller action, a job's handler or a constructor it runs.
     */
    public function sharesInjectedParameter(string $class, string $method, string $parameter): TrinaryLogic
    {
        $key = strtolower($class . '::' . $method . '::' . $parameter);

        if (! array_key_exists($key, $this->injectedParameters)) {
            return TrinaryLogic::createNo();
        }

        return $this->injectedParameters[$key] === null ? TrinaryLogic::createMaybe() : $this->sharesAbstract($this->injectedParameters[$key]);
    }

    /**
     * Determine whether the container built every instance the method handles an operation for, so
     * the properties its constructor promotes hold what the container injected.
     *
     * A queued class is restored from its payload, except one the dispatcher runs as a listener. A
     * method no booted handler reaches is judged by its own class.
     */
    public function isContainerBuilt(string $class, string $method): bool
    {
        $receivers = array_keys($this->contextReceivers[strtolower($class . '::' . $method)] ?? [$class => true]);

        return array_all($receivers, function (string $receiver): bool {
            $reflection = $this->getClass($receiver);

            return $reflection !== null
                && (! $reflection->is(ShouldQueue::class) || isset($this->queuedListeners[strtolower($reflection->getName())]));
        });
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
            $injected = $class === null
                || $this->getContextualAttribute($parameter) !== null
                || $parameter->isDefaultValueAvailable()
                || $bound
                || ($this->getClass($class)?->is(UrlRoutable::class) === true
                    && array_intersect([$parameter->getName(), Str::snake($parameter->getName())], $names) !== [])
                ? null
                : $class;

            // Another route may run the same action with other bindings.
            if ($actionClass !== null) {
                $this->recordInjection($actionClass, $method, $parameter->getName(), $injected);
            }

            if ($injected !== null) {
                $this->make($injected, null);
            }
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
        $this->contextReceivers[$key][$receiver] = true;
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
     * Discover the class-string event listeners, which the dispatcher resolves from the container,
     * and the operation each handles when its event is dispatched for one.
     */
    private function discoverListeners(): void
    {
        $events = $this->container->bound('events') ? $this->container->make('events') : null;

        if (! $events instanceof EventDispatcher) {
            return;
        }

        foreach ([...$events->getRawListeners(), ...(fn (): array => $this->wildcards)->call($events)] as $event => $listeners) {
            $context = self::EVENT_CONTEXTS[$event] ?? (is_string($event) && str_starts_with($event, 'eloquent.') ? 'operation' : null);

            foreach ($listeners as $listener) {
                [$class, $method] = match (true) {
                    is_string($listener) => Str::parseCallback($listener, 'handle'),
                    is_array($listener) && is_string($listener[0] ?? null) => [$listener[0], $listener[1] ?? null],
                    default => [null, null],
                };

                $reflection = $class === null ? null : $this->getClass($class);

                if ($reflection === null) {
                    continue;
                }

                if (! is_string($method)) {
                    $this->make($reflection->getName(), null);

                    continue;
                }

                // Dispatcher::createClassCallable() falls back to __invoke().
                $this->addHandlerContext($reflection, $reflection->hasNativeMethod($method) ? $method : '__invoke', $context);
            }
        }
    }

    /**
     * Discover the observers models register through #[ObservedBy] when they boot, whose event methods
     * run during any operation that persists models.
     *
     * @param array<string, list<string>> $observers each observer class with the model events it can handle
     */
    private function discoverObservers(array $observers): void
    {
        foreach ($observers as $observer => $events) {
            $reflection = $this->getClass($observer);

            if ($reflection === null) {
                continue;
            }

            // HasEvents::registerObserver() registers each event method the observer has.
            foreach ($events as $event) {
                if ($reflection->hasNativeMethod($event)) {
                    $this->addHandlerContext($reflection, $event, 'operation');
                }
            }
        }
    }

    /**
     * Resolve a listener as the dispatcher does and give the method it runs the operation its event
     * belongs to, when that is known.
     *
     * A queued listener runs as a job whatever its event: CallQueuedListener resolves it from the
     * container in the queue worker and calls the same method.
     */
    private function addHandlerContext(ClassReflection $class, string $method, ?string $context): void
    {
        [$shared, $receiver] = $this->makeHandler($class);

        if ($class->is(ShouldQueue::class)) {
            $context = 'job';
            $this->queuedListeners[strtolower($receiver->getName())] = true;
        }

        if ($context !== null && $receiver->hasNativeMethod($method)) {
            $this->addContext($receiver->getName(), $receiver->getNativeMethod($method)->getDeclaringClass()->getName(), $method, $context, $shared);
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
                $this->recordInjection($declaringClass, $method, $parameter->getName(), null);

                continue;
            }

            // A contextual binding for the job replaces the dependency; a closure's result is unknown.
            // Another job inheriting the handler may inject something else.
            $concrete = $this->getContextualConcrete($reflection->getName(), $this->container->getAlias($dependency));

            $this->recordInjection($declaringClass, $method, $parameter->getName(), $concrete === null ? $dependency : (is_string($concrete) ? $concrete : null));
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
            if (is_string($concrete)) {
                return $this->make($concrete, null);
            }

            $this->addFactoryBuild($abstract);

            return [self::UNKNOWN, null];
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
        $concrete = $binding === null ? null : self::getBindingConcrete($binding['concrete']);

        if (array_key_exists($abstract, $this->instances)) {
            $instance = $this->instances[$abstract];

            // An instance its binding built got its constructor arguments from the container.
            return is_object($instance)
                ? [self::SHARED, $instance::class, $concrete !== null && strtolower($concrete) === strtolower($instance::class)]
                : [self::UNKNOWN, null, false];
        }

        if ($binding !== null) {
            $lifetime = match (true) {
                isset($this->scopedInstances[$abstract]) => self::SCOPED,
                $binding['shared'] => self::SHARED,
                default => self::FRESH,
            };

            // The container caches whatever a shared or scoped binding builds, even when the class it
            // builds is unknown. A bind() binding's lifetime is the built class's, and a factory may
            // return a shared object.
            $unknown = [$lifetime === self::FRESH ? self::UNKNOWN : $lifetime, null, false];

            if ($concrete === null) {
                $this->addFactoryBuild($abstract);

                return $unknown;
            }

            if ($concrete === $abstract) {
                $class = $this->getBuildableClass($concrete);

                return $class === null ? $unknown : [$lifetime, $class->getName(), ! $class->is(SelfBuilding::class)];
            }

            [$concreteLifetime, $class, $knownArguments] = $this->getLifetime($this->container->getAlias($concrete), false);

            if ($concreteLifetime === self::UNKNOWN) {
                return $unknown;
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
            [$outcome, $injected] = $knownArguments ? $this->resolveConstructorParameter($parameter, $class) : [self::UNKNOWN, null];

            $this->recordInjection($declaringClass, '__construct', $parameter->getName(), $injected);

            if ($shared) {
                $parameterKey = strtolower($declaringClass . '::' . $parameter->getName());
                $this->freshParameters[$parameterKey] = ($this->freshParameters[$parameterKey] ?? true)
                    && in_array($outcome, [self::DEFAULT, self::FRESH], true);
            }
        }
    }

    /**
     * Record that a factory may run the class's constructor, with arguments the analysis cannot see.
     */
    private function addFactoryBuild(string $class): void
    {
        $constructor = $this->getClass($class)?->getNativeReflection()->getConstructor();

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $this->recordInjection($constructor->getDeclaringClass()->getName(), '__construct', $parameter->getName(), null);
        }
    }

    /**
     * Record the abstract the container injects into a parameter on one of the calls it makes. Calls
     * that inject different abstracts, or an unknown one, leave it unknown.
     */
    private function recordInjection(string $class, string $method, string $parameter, ?string $abstract): void
    {
        $key = strtolower($class . '::' . $method . '::' . $parameter);
        $abstract = $abstract === null ? null : $this->container->getAlias($abstract);

        $this->injectedParameters[$key] = ! array_key_exists($key, $this->injectedParameters) || $this->injectedParameters[$key] === $abstract
            ? $abstract
            : null;
    }

    /**
     * Resolve a constructor parameter as Container::resolveRecipeParameters() does for the class being
     * built, and return what it receives with the abstract injected, when it is one known abstract.
     *
     * @return array{string, null|string}
     */
    private function resolveConstructorParameter(ReflectionParameter $parameter, string $consumer): array
    {
        if ($this->getContextualAttribute($parameter) !== null) {
            return [self::UNKNOWN, null];
        }

        $class = $this->getParameterClass($parameter);

        if ($class === null) {
            return [
                $parameter->isDefaultValueAvailable() && ! isset($this->contextual[$consumer]['$' . $parameter->getName()])
                    ? self::DEFAULT
                    : self::UNKNOWN,
                null,
            ];
        }

        if ($parameter->isDefaultValueAvailable()
            && ! $this->container->bound($class)
            && ! isset($this->contextual[$consumer][$class])) {
            return [self::DEFAULT, null];
        }

        $concrete = $this->getContextualConcrete($consumer, $this->container->getAlias($class));

        if ($parameter->isVariadic() && is_array($concrete)) {
            foreach ($concrete as $abstract) {
                if (is_string($abstract)) {
                    $this->make($abstract, $consumer);
                }
            }

            return [self::UNKNOWN, null];
        }

        // A contextual class name replaces the dependency; a contextual closure's result is unknown.
        return [$this->make($class, $consumer)[0], $concrete === null ? $class : (is_string($concrete) ? $concrete : null)];
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
    public static function getBindingConcrete(Closure $closure): ?string
    {
        $reflection = new ReflectionFunction($closure);
        $concrete = $reflection->getStaticVariables()['concrete'] ?? null;

        // Container::getClosure() wraps a class-name concrete; any other closure is a factory.
        return $reflection->getClosureScopeClass()?->getName() === Container::class && is_string($concrete)
            ? $concrete
            : null;
    }

    /**
     * Determine whether the container builds the class itself: an instantiable class without its own
     * Bind or BindWhen attributes.
     */
    public static function isBuildableClass(ClassReflection $class): bool
    {
        if (! $class->isClass() || $class->isAbstract()) {
            return false;
        }

        $native = $class->getNativeReflection();

        return $native->getAttributes(Bind::class) === [] && $native->getAttributes(BindWhen::class) === [];
    }

    /**
     * Get the class the container can build for the name, or null when it is unknown or not buildable.
     */
    private function getBuildableClass(string $name): ?ClassReflection
    {
        $class = $this->getClass($name);

        return $class !== null && self::isBuildableClass($class) ? $class : null;
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
