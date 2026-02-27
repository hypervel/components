<?php

declare(strict_types=1);

namespace Hypervel\Events;

use Closure;
use Exception;
use Hypervel\Bus\UniqueLock;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Event\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Event\ShouldDispatchAfterCommit;
use Hypervel\Contracts\Event\ShouldHandleEventsAfterCommit;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\DeleteWhenMissingModels;
use Hypervel\Queue\Attributes\FailOnTimeout;
use Hypervel\Queue\Attributes\MaxExceptions;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\Timeout;
use Hypervel\Queue\Attributes\Tries;
use Hypervel\Queue\Attributes\UniqueFor;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Queue\Concerns\ResolvesQueueRoutes;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\ReflectsClosures;
use ReflectionClass;

use function Hypervel\Support\enum_value;

class Dispatcher implements DispatcherContract
{
    use Macroable;
    use ReadsQueueAttributes;
    use ReflectsClosures;
    use ResolvesQueueRoutes;

    /**
     * The IoC container instance.
     */
    protected ContainerContract $container;

    /**
     * The registered event listeners.
     *
     * @var array<string, null|array|callable|class-string>
     */
    protected array $listeners = [];

    /**
     * The wildcard listeners.
     *
     * @var array<string, array<int, array|Closure|string>>
     */
    protected array $wildcards = [];

    /**
     * The cached wildcard listeners.
     *
     * @var array<string, array<int, Closure>>
     */
    protected array $wildcardsCache = [];

    /**
     * The cached prepared listeners.
     */
    protected array $listenersCache = [];

    /**
     * The queue resolver instance.
     *
     * @var callable(): QueueFactory
     */
    protected $queueResolver;

    /**
     * The database transaction manager resolver instance.
     *
     * @var callable
     */
    protected $transactionManagerResolver;

    // Deferred event state is stored in Context (__events.deferring, __events.deferred_events,
    // __events.events_to_defer) for coroutine safety. See defer() and shouldDeferEvent().

    /**
     * Create a new event dispatcher instance.
     */
    public function __construct(?ContainerContract $container = null)
    {
        $this->container = $container ?: Container::getInstance();
    }

    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(array|Closure|QueuedClosure|string $events, array|Closure|QueuedClosure|string|null $listener = null): void
    {
        if ($events instanceof Closure) {
            (new Collection($this->firstClosureParameterTypes($events)))
                ->each(function ($event) use ($events) {
                    $this->listen($event, $events);
                });

            return;
        }
        if ($events instanceof QueuedClosure) {
            (new Collection($this->firstClosureParameterTypes($events->closure)))
                ->each(function ($event) use ($events) {
                    $this->listen($event, $events->resolve());
                });

            return;
        }
        if ($listener instanceof QueuedClosure) {
            $listener = $listener->resolve();
        }

        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][] = $listener;
            }
        }

        $this->listenersCache = [];
    }

    /**
     * Set up a wildcard listener callback.
     */
    protected function setupWildcardListen(string $event, array|Closure|string $listener): void
    {
        $this->wildcards[$event][] = $listener;

        $this->wildcardsCache = [];
        $this->listenersCache = [];
    }

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool
    {
        return isset($this->listeners[$eventName])
            || isset($this->wildcards[$eventName])
            || $this->hasWildcardListeners($eventName);
    }

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcardListeners(string $eventName): bool
    {
        foreach ($this->wildcards as $key => $listeners) {
            if (Str::is($key, $eventName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register an event and payload to be fired later.
     */
    public function push(string $event, mixed $payload = []): void
    {
        $this->listen($event . '_pushed', function () use ($event, $payload) {
            $this->dispatch($event, $payload);
        });
    }

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void
    {
        $this->dispatch($event . '_pushed');
    }

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void
    {
        $subscriber = $this->resolveSubscriber($subscriber);

        $events = $subscriber->subscribe($this);

        if (is_array($events)) {
            foreach ($events as $event => $listeners) {
                foreach (Arr::wrap($listeners) as $listener) {
                    if (is_string($listener) && method_exists($subscriber, $listener)) {
                        $this->listen($event, [get_class($subscriber), $listener]);

                        continue;
                    }

                    $this->listen($event, $listener);
                }
            }
        }
    }

    /**
     * Resolve the subscriber instance.
     */
    protected function resolveSubscriber(object|string $subscriber): mixed
    {
        if (is_string($subscriber)) {
            return $this->container->make($subscriber);
        }

        return $subscriber;
    }

    /**
     * Fire an event until the first non-null response is returned.
     */
    public function until(object|string $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * Fire an event and call the listeners.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        // When the given "event" is actually an object, we will assume it is an event
        // object, and use the class as the event name and this event itself as the
        // payload to the handler, which makes object-based events quite simple.
        [$isEventObject, $parsedEvent, $parsedPayload] = [
            is_object($event),
            ...$this->parseEventAndPayload($event, $payload),
        ];

        if ($this->shouldDeferEvent($parsedEvent)) {
            Context::override('__events.deferred_events', function (?array $events) use ($event, $payload, $halt) {
                $events = $events ?? [];
                $events[] = [$event, $payload, $halt];

                return $events;
            });

            return null;
        }

        // If the event is not intended to be dispatched unless the current database
        // transaction is successful, we'll register a callback which will handle
        // dispatching this event on the next successful DB transaction commit.
        if ($isEventObject
            && $parsedPayload[0] instanceof ShouldDispatchAfterCommit
            && ! is_null($transactions = $this->resolveTransactionManager())) {
            $transactions->addCallback(
                fn () => $this->invokeListeners($parsedEvent, $parsedPayload, $halt)
            );

            return null;
        }

        return $this->invokeListeners($parsedEvent, $parsedPayload, $halt);
    }

    /**
     * Broadcast an event and call its listeners.
     */
    protected function invokeListeners(string $event, array $payload, bool $halt = false): mixed
    {
        if ($this->shouldBroadcast($payload)) {
            $this->broadcastEvent($payload[0]);
        }

        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

            // If a response is returned from the listener and event halting is enabled
            // we will just return this response, and not call the rest of the event
            // listeners. Otherwise we will add the response on the response list.
            if ($halt && ! is_null($response)) {
                return $response;
            }

            // If a boolean false is returned from a listener, we will stop propagating
            // the event to any further listeners down in the chain, else we keep on
            // looping through the listeners and firing every one in our sequence.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Parse the given event and payload and prepare them for dispatching.
     *
     * @return array{string, array}
     */
    protected function parseEventAndPayload(object|string $event, mixed $payload): array
    {
        if (is_object($event)) {
            [$payload, $event] = [[$event], get_class($event)];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * Determine if the payload has a broadcastable event.
     */
    protected function shouldBroadcast(array $payload): bool
    {
        return isset($payload[0])
            && $payload[0] instanceof ShouldBroadcast
            && $this->broadcastWhen($payload[0]);
    }

    /**
     * Check if the event should be broadcasted by the condition.
     */
    protected function broadcastWhen(mixed $event): bool
    {
        return method_exists($event, 'broadcastWhen')
            ? $event->broadcastWhen()
            : true;
    }

    /**
     * Broadcast the given event class.
     */
    protected function broadcastEvent(ShouldBroadcast $event): void
    {
        $this->container->make(BroadcastFactory::class)->queue($event); // @phpstan-ignore method.notFound (queue() is on concrete BroadcastManager, not the Factory contract — matches Laravel)
    }

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListeners(string $eventName): array
    {
        if (isset($this->listenersCache[$eventName])) {
            return $this->listenersCache[$eventName];
        }

        $listeners = array_merge(
            $this->prepareListeners($eventName),
            $this->wildcardsCache[$eventName] ?? $this->getWildcardListeners($eventName)
        );

        $listeners = class_exists($eventName, false)
            ? $this->addInterfaceListeners($eventName, $listeners)
            : $listeners;

        return $this->listenersCache[$eventName] = $listeners;
    }

    /**
     * Get the wildcard listeners for the event.
     */
    protected function getWildcardListeners(string $eventName): array
    {
        $wildcards = [];

        foreach ($this->wildcards as $key => $listeners) {
            if (Str::is($key, $eventName)) {
                foreach ($listeners as $listener) {
                    $wildcards[] = $this->makeListener($listener, true);
                }
            }
        }

        return $this->wildcardsCache[$eventName] = $wildcards;
    }

    /**
     * Add the listeners for the event's interfaces to the given array.
     */
    protected function addInterfaceListeners(string $eventName, array $listeners = []): array
    {
        foreach (class_implements($eventName) as $interface) {
            if (isset($this->listeners[$interface])) {
                foreach ($this->prepareListeners($interface) as $names) {
                    $listeners = array_merge($listeners, (array) $names);
                }
            }
        }

        return $listeners;
    }

    /**
     * Prepare the listeners for a given event.
     *
     * @return Closure[]
     */
    protected function prepareListeners(string $eventName): array
    {
        $listeners = [];

        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            $listeners[] = $this->makeListener($listener);
        }

        return $listeners;
    }

    /**
     * Register an event listener with the dispatcher.
     */
    public function makeListener(array|Closure|string $listener, bool $wildcard = false): Closure
    {
        if (is_string($listener)) {
            return $this->createClassListener($listener, $wildcard);
        }

        if (is_array($listener) && isset($listener[0]) && is_string($listener[0])) {
            return $this->createClassListener($listener, $wildcard);
        }

        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            }

            return $listener(...array_values($payload));
        };
    }

    /**
     * Create a class based listener using the IoC container.
     */
    public function createClassListener(array|string $listener, bool $wildcard = false): Closure
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return call_user_func($this->createClassCallable($listener), $event, $payload);
            }

            $callable = $this->createClassCallable($listener);

            return $callable(...array_values($payload));
        };
    }

    /**
     * Create the class based event callable.
     *
     * @param array{class-string, string}|string $listener
     */
    protected function createClassCallable(array|string $listener): callable
    {
        [$class, $method] = is_array($listener)
            ? $listener
            : $this->parseClassCallable($listener);

        if (! method_exists($class, $method)) {
            $method = '__invoke';
        }

        if ($this->handlerShouldBeQueued($class)) {
            return $this->createQueuedHandlerCallable($class, $method);
        }

        $listener = $this->container->make($class);

        return $this->handlerShouldBeDispatchedAfterDatabaseTransactions($listener)
            ? $this->createCallbackForListenerRunningAfterCommits($listener, $method)
            : [$listener, $method];
    }

    /**
     * Parse the class listener into class and method.
     *
     * @return array{class-string, string}
     */
    protected function parseClassCallable(string $listener): array
    {
        return Str::parseCallback($listener, 'handle');
    }

    /**
     * Determine if the event handler class should be queued.
     */
    protected function handlerShouldBeQueued(string $class): bool
    {
        try {
            return (new ReflectionClass($class))->implementsInterface(
                ShouldQueue::class
            );
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Create a callable for putting an event handler on the queue.
     *
     * @param class-string $class
     */
    protected function createQueuedHandlerCallable(string $class, string $method): Closure
    {
        return function () use ($class, $method) {
            $arguments = array_map(function ($a) {
                return is_object($a) ? clone $a : $a;
            }, func_get_args());

            if ($this->handlerWantsToBeQueued($class, $arguments)) {
                $this->queueHandler($class, $method, $arguments);
            }
        };
    }

    /**
     * Determine if the given event handler should be dispatched after all database transactions have committed.
     */
    protected function handlerShouldBeDispatchedAfterDatabaseTransactions(mixed $listener): bool
    {
        return (($listener->afterCommit ?? null)
            || $listener instanceof ShouldHandleEventsAfterCommit)
            && $this->resolveTransactionManager();
    }

    /**
     * Create a callable for dispatching a listener after database transactions.
     */
    protected function createCallbackForListenerRunningAfterCommits(mixed $listener, string $method): Closure
    {
        return function () use ($method, $listener) {
            $payload = func_get_args();

            $this->resolveTransactionManager()->addCallback(
                function () use ($listener, $method, $payload) {
                    $listener->{$method}(...$payload);
                }
            );
        };
    }

    /**
     * Determine if the event handler wants to be queued.
     *
     * @param class-string $class
     */
    protected function handlerWantsToBeQueued(string $class, array $arguments): bool
    {
        $instance = $this->container->make($class);

        if (method_exists($instance, 'shouldQueue')) {
            return $instance->shouldQueue($arguments[0]);
        }

        return true;
    }

    /**
     * Queue the handler class.
     *
     * @param class-string $class
     */
    protected function queueHandler(string $class, string $method, array $arguments): void
    {
        [$listener, $job] = $this->createListenerAndJob($class, $method, $arguments);

        if ($job->shouldBeUnique
            && ! (new UniqueLock($this->container->make(Cache::class)))->acquire($job)) {
            return;
        }

        $connectionName = method_exists($listener, 'viaConnection')
            ? (isset($arguments[0]) ? $listener->viaConnection($arguments[0]) : $listener->viaConnection())
            : $this->getAttributeValue($listener, Connection::class, 'connection');

        $connection = $this->resolveQueue()->connection(
            $connectionName ?? $this->resolveConnectionFromQueueRoute($listener) ?? null
        );

        $queue = method_exists($listener, 'viaQueue')
            ? (isset($arguments[0]) ? $listener->viaQueue($arguments[0]) : $listener->viaQueue())
            : $this->getAttributeValue($listener, QueueAttribute::class, 'queue');

        $delay = method_exists($listener, 'withDelay')
            ? (isset($arguments[0]) ? $listener->withDelay($arguments[0]) : $listener->withDelay())
            : $listener->delay ?? null;

        if (is_null($queue)) {
            $queue = $this->resolveQueueFromQueueRoute($listener) ?? null;
        }

        is_null($delay)
            ? $connection->pushOn(enum_value($queue), $job)
            : $connection->laterOn(enum_value($queue), $delay, $job);
    }

    /**
     * Create the listener and job for a queued listener.
     *
     * @template TListener
     *
     * @param class-string<TListener> $class
     * @return array{TListener, CallQueuedListener}
     */
    protected function createListenerAndJob(string $class, string $method, array $arguments): array
    {
        $listener = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return [$listener, $this->propagateListenerOptions(
            $listener,
            new CallQueuedListener($class, $method, $arguments)
        )];
    }

    /**
     * Propagate listener options to the job.
     */
    protected function propagateListenerOptions(mixed $listener, CallQueuedListener $job): CallQueuedListener
    {
        return tap($job, function ($job) use ($listener) {
            $data = array_values($job->data);

            if ($listener instanceof ShouldQueueAfterCommit) {
                $job->afterCommit = true;
            } else {
                $job->afterCommit = property_exists($listener, 'afterCommit') ? $listener->afterCommit : null;
            }

            $job->backoff = method_exists($listener, 'backoff') ? $listener->backoff(...$data) : $this->getAttributeValue($listener, Backoff::class, 'backoff');
            $job->maxExceptions = $this->getAttributeValue($listener, MaxExceptions::class, 'maxExceptions');
            $job->retryUntil = method_exists($listener, 'retryUntil') ? $listener->retryUntil(...$data) : null;
            $job->shouldBeEncrypted = $listener instanceof ShouldBeEncrypted;
            $job->timeout = $this->getAttributeValue($listener, Timeout::class, 'timeout');
            $job->failOnTimeout = $this->getAttributeValue($listener, FailOnTimeout::class, 'failOnTimeout') ?? false;
            $job->deleteWhenMissingModels = $this->getAttributeValue($listener, DeleteWhenMissingModels::class, 'deleteWhenMissingModels') ?? false;
            $job->tries = method_exists($listener, 'tries') ? $listener->tries(...$data) : $this->getAttributeValue($listener, Tries::class, 'tries');
            $job->messageGroup = method_exists($listener, 'messageGroup') ? $listener->messageGroup(...$data) : ($listener->messageGroup ?? null);
            $job->withDeduplicator(
                method_exists($listener, 'deduplicator')
                ? $listener->deduplicator(...$data)
                : (method_exists($listener, 'deduplicationId') ? $listener->deduplicationId(...) : null)
            );

            $job->through(array_merge(
                method_exists($listener, 'middleware') ? $listener->middleware(...$data) : [],
                $listener->middleware ?? []
            ));

            $job->shouldBeUnique = $listener instanceof ShouldBeUnique;
            $job->shouldBeUniqueUntilProcessing = $listener instanceof ShouldBeUniqueUntilProcessing;

            if ($job->shouldBeUnique) {
                $job->uniqueId = method_exists($listener, 'uniqueId')
                    ? $listener->uniqueId(...$data)
                    : ($listener->uniqueId ?? null);

                $job->uniqueFor = method_exists($listener, 'uniqueFor')
                    ? $listener->uniqueFor(...$data)
                    : ($this->getAttributeValue($listener, UniqueFor::class, 'uniqueFor') ?? 0);
            }
        });
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        if (str_contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event]);
        }

        foreach ($this->wildcardsCache as $key => $listeners) {
            if (Str::is($event, $key)) {
                unset($this->wildcardsCache[$key]);
            }
        }

        $this->listenersCache = [];
    }

    /**
     * Forget all of the pushed listeners.
     */
    public function forgetPushed(): void
    {
        foreach ($this->listeners as $key => $value) {
            if (str_ends_with($key, '_pushed')) {
                $this->forget($key);
            }
        }
    }

    /**
     * Get the queue implementation from the resolver.
     */
    protected function resolveQueue(): QueueFactory
    {
        return call_user_func($this->queueResolver);
    }

    /**
     * Set the queue resolver implementation.
     *
     * @param callable(): QueueFactory $resolver
     */
    public function setQueueResolver(callable $resolver): static
    {
        $this->queueResolver = $resolver;

        return $this;
    }

    /**
     * Get the database transaction manager implementation from the resolver.
     */
    protected function resolveTransactionManager(): ?\Hypervel\Database\DatabaseTransactionsManager
    {
        if ($this->transactionManagerResolver === null) {
            return null;
        }

        return call_user_func($this->transactionManagerResolver);
    }

    /**
     * Set the database transaction manager resolver implementation.
     *
     * @param callable(): ?\Hypervel\Database\DatabaseTransactionsManager $resolver
     */
    public function setTransactionManagerResolver(callable $resolver): static
    {
        $this->transactionManagerResolver = $resolver;

        return $this;
    }

    /**
     * Execute the given callback while deferring events, then dispatch all deferred events.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @param null|string[] $events
     * @return TResult
     */
    public function defer(callable $callback, ?array $events = null): mixed
    {
        $wasDeferring = Context::get('__events.deferring', false);
        $previousDeferredEvents = Context::get('__events.deferred_events', []);
        $previousEventsToDefer = Context::get('__events.events_to_defer');

        Context::set('__events.deferring', true);
        Context::set('__events.deferred_events', []);
        Context::set('__events.events_to_defer', $events);

        try {
            $result = $callback();

            Context::set('__events.deferring', false);

            foreach (Context::get('__events.deferred_events', []) as $args) {
                $this->dispatch(...$args);
            }

            return $result;
        } finally {
            Context::set('__events.deferring', $wasDeferring);
            Context::set('__events.deferred_events', $previousDeferredEvents);
            Context::set('__events.events_to_defer', $previousEventsToDefer);
        }
    }

    /**
     * Determine if the given event should be deferred.
     */
    protected function shouldDeferEvent(string $event): bool
    {
        if (! Context::get('__events.deferring', false)) {
            return false;
        }

        $eventsToDefer = Context::get('__events.events_to_defer');

        return $eventsToDefer === null || in_array($event, $eventsToDefer);
    }

    /**
     * Get the raw, unprepared listeners.
     */
    public function getRawListeners(): array
    {
        return $this->listeners;
    }
}
