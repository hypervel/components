<?php

declare(strict_types=1);

namespace Hypervel\Event;

use Closure;
use Exception;
use Hypervel\Support\Arr;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Stringable\Str;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Event\Contracts\Dispatcher as EventDispatcherContract;
use Hypervel\Event\Contracts\ListenerProvider as ListenerProviderContract;
use Hypervel\Event\Contracts\ShouldDispatchAfterCommit;
use Hypervel\Event\Contracts\ShouldHandleEventsAfterCommit;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Support\Traits\ReflectsClosures;
use Illuminate\Events\CallQueuedListener;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class EventDispatcher implements EventDispatcherContract
{
    use ReflectsClosures;

    /**
     * The queue resolver instance.
     *
     * @var callable
     */
    protected $queueResolver;

    /**
     * The database transaction manager resolver instance.
     *
     * @var callable
     */
    protected $transactionManagerResolver;

    public function __construct(
        protected ListenerProviderContract $listeners,
        protected ?LoggerInterface $logger = null,
        protected ?ContainerInterface $container = null
    ) {
        if (! $container && ApplicationContext::hasContainer()) {
            $this->container = ApplicationContext::getContainer();
        }
    }

    /**
     * Fire an event and call the listeners.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        if ($this->shouldDeferEvent($event)) {
            Context::override('__event.deferred_events', function (?array $events) use ($event, $payload, $halt) {
                $events = $events ?? [];
                $events[] = [$event, $payload, $halt];

                return $events;
            });

            return $event;
        }

        // If the event is not intended to be dispatched unless the current database
        // transaction is successful, we'll register a callback which will handle
        // dispatching this event on the next successful DB transaction commit.
        if ($event instanceof ShouldDispatchAfterCommit
            && ! is_null($transactions = $this->resolveTransactionManager())) {
            $transactions->addCallback(
                fn () => $this->invokeListeners($event, $payload, $halt)
            );

            return $event;
        }

        return $this->invokeListeners($event, $payload, $halt);
    }

    /**
     * Dump the event and listeners to the log.
     */
    protected function dump(mixed $listener, object|string $event): void
    {
        if (! $this->logger) {
            return;
        }

        $eventName = is_string($event) ? $event : get_class($event);
        $listenerName = '[ERROR TYPE]';

        if (is_array($listener)) {
            $listenerName = is_string($listener[0]) ? $listener[0] : get_class($listener[0]);
        } elseif (is_string($listener)) {
            $listenerName = $listener;
        } elseif (is_object($listener)) {
            $listenerName = get_class($listener);
        }

        $this->logger->debug(sprintf('Event %s handled by %s listener.', $eventName, $listenerName));
    }

    /**
     * Register an event listener with the listener provider.
     */
    public function listen(
        array|Closure|QueuedClosure|string $events,
        array|Closure|int|QueuedClosure|string|null $listener = null,
        int $priority = ListenerData::DEFAULT_PRIORITY
    ): void {
        if ($events instanceof Closure) {
            foreach ((array) $this->firstClosureParameterTypes($events) as $event) {
                $this->listeners->on($event, $events, is_int($listener) ? $listener : $priority);
            }

            return;
        }

        if ($events instanceof QueuedClosure) {
            foreach ((array) $this->firstClosureParameterTypes($events->closure) as $event) {
                $this->listeners->on($event, $events->resolve(), is_int($listener) ? $listener : $priority);
            }

            return;
        }

        if ($listener instanceof QueuedClosure) {
            $listener = $listener->resolve();
        }

        foreach ((array) $events as $event) {
            $this->listeners->on($event, $listener, $priority);
        }
    }

    /**
     * Fire an event until the first non-null response is returned.
     */
    public function until(object|string $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * Broadcast an event and call the listeners.
     */
    protected function invokeListeners(object|string $event, mixed $payload, bool $halt = false): mixed
    {
        if ($this->shouldBroadcast($event)) {
            $this->broadcastEvent($event);
        }

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

            $this->dump($listener, $event);

            // If halting and listener returned a non-null response, return it immediately
            if ($halt && ! is_null($response)) {
                return $response;
            }

            // If listener returns false, stop propagation
            if ($response === false || ($event instanceof StoppableEventInterface && $event->isPropagationStopped())) {
                break;
            }
        }

        return $event;
    }

    /**
     * Determine if the payload has a broadcastable event.
     */
    protected function shouldBroadcast(object|string $event): bool
    {
        return is_object($event)
            && $event instanceof ShouldBroadcast
            && $this->broadcastWhen($event);
    }

    /**
     * Check if the event should be broadcasted by the condition.
     */
    protected function broadcastWhen(mixed $event): bool
    {
        return method_exists($event, 'broadcastWhen') ? $event->broadcastWhen() : true;
    }

    /**
     * Broadcast the given event class.
     */
    protected function broadcastEvent(ShouldBroadcast $event): void
    {
        $this->container->get(BroadcastFactory::class)->queue($event);
    }

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListeners(object|string $eventName): iterable
    {
        return $this->prepareListeners($eventName);
    }

    /**
     * Get the listeners for a given event.
     *
     * @return Closure[]
     */
    protected function prepareListeners(object|string $eventName): array
    {
        $listeners = [];

        foreach ($this->listeners->getListenersForEvent($eventName) as $listener) {
            $listeners[] = $this->makeListener($listener);
        }

        return $listeners;
    }

    /**
     * Create a callable for a class based listener.
     */
    protected function makeListener(array|Closure|string $listener): Closure
    {
        if (is_string($listener) || (is_array($listener) && ((isset($listener[0]) && is_string($listener[0])) || is_callable($listener)))) {
            return $this->createClassListener($listener);
        }

        return function ($event, $payload) use ($listener) {
            if (is_array($payload)) {
                return $listener($event, ...array_values($payload));
            }

            return $listener($event, $payload);
        };
    }

    /**
     * Create a class based listener.
     */
    protected function createClassListener(array|string $listener): Closure
    {
        return function (object|string $event, mixed $payload) use ($listener) {
            $callable = $this->createClassCallable($listener);

            if (is_array($payload)) {
                return $callable($event, ...array_values($payload));
            }

            return $callable($event, $payload);
        };
    }

    /**
     * Create a callable based listener.
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

        $listener = is_string($class) ? $this->container->get($class) : $class;

        return $this->handlerShouldBeDispatchedAfterDatabaseTransactions($listener)
            ? $this->createCallbackForListenerRunningAfterCommits($listener, $method)
            : [$listener, $method];
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
     * Parse the class listener into class and method.
     */
    protected function parseClassCallable(string $listener): array
    {
        return Str::parseCallback($listener, 'handle');
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
     * Forget all of the pushed listeners.
     */
    public function forgetPushed(): void
    {
        foreach ($this->listeners->all() as $key => $_) {
            if (str_ends_with($key, '_pushed')) {
                $this->forget($key);
            }
        }
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        $this->listeners->forget($event);
    }

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool
    {
        return $this->listeners->has($eventName);
    }

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcardListeners(string $eventName): bool
    {
        return $this->listeners->hasWildcard($eventName);
    }

    /**
     * Get the queue implementation from the resolver.
     */
    protected function resolveQueue(): QueueFactoryContract
    {
        return call_user_func($this->queueResolver);
    }

    /**
     * Set the queue resolver implementation.
     *
     * @return $this
     */
    public function setQueueResolver(callable $resolver): static
    {
        $this->queueResolver = $resolver;

        return $this;
    }

    /**
     * Get the database transaction manager implementation from the resolver.
     */
    protected function resolveTransactionManager(): ?DatabaseTransactionsManager
    {
        if ($this->transactionManagerResolver === null) {
            return null;
        }

        return call_user_func($this->transactionManagerResolver);
    }

    /**
     * Set the database transaction manager resolver implementation.
     */
    public function setTransactionManagerResolver(callable $resolver): static
    {
        $this->transactionManagerResolver = $resolver;

        return $this;
    }

    /**
     * Determine if the event handler should be queued.
     */
    protected function handlerShouldBeQueued(object|string $class): bool
    {
        try {
            if (is_string($class)) {
                return (new ReflectionClass($class))->implementsInterface(ShouldQueue::class);
            }

            return $class instanceof ShouldQueue;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Create a callable for putting an event handler on the queue.
     */
    protected function createQueuedHandlerCallable(object|string $class, string $method): Closure
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
     */
    protected function handlerWantsToBeQueued(object|string $class, array $arguments): bool
    {
        $instance = is_string($class) ? $this->container->get($class) : $class;

        if (method_exists($instance, 'shouldQueue')) {
            return $instance->shouldQueue($arguments[0]);
        }

        return true;
    }

    /**
     * Queue the handler execution.
     */
    protected function queueHandler(object|string $class, string $method, array $arguments): void
    {
        [$listener, $job] = $this->createListenerAndJob($class, $method, $arguments);

        $connection = $this->resolveQueue()->connection(method_exists($listener, 'viaConnection')
            ? (isset($arguments[1]) ? $listener->viaConnection($arguments[1]) : $listener->viaConnection())
            : $listener->connection ?? null);

        $queue = method_exists($listener, 'viaQueue')
            ? (isset($arguments[1]) ? $listener->viaQueue($arguments[1]) : $listener->viaQueue())
            : $listener->queue ?? null;

        $delay = method_exists($listener, 'withDelay')
            ? (isset($arguments[1]) ? $listener->withDelay($arguments[1]) : $listener->withDelay())
            : $listener->delay ?? null;

        is_null($delay)
            ? $connection->pushOn($queue, $job)
            : $connection->laterOn($queue, $delay, $job);
    }

    /**
     * Create a listener and job for the queued listener.
     */
    protected function createListenerAndJob(object|string $class, string $method, array $arguments): array
    {
        $listener = is_string($class) ? (new ReflectionClass($class))->newInstanceWithoutConstructor() : $class;
        $class = is_string($class) ? $class : get_class($class);

        return [$listener, $this->propagateListenerOptions(
            $listener,
            new CallQueuedListener($class, $method, $arguments)
        )];
    }

    /**
     * Propagate the listener options to the job.
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

            $job->backoff = method_exists($listener, 'backoff') ? $listener->backoff(...$data) : ($listener->backoff ?? null);
            $job->maxExceptions = $listener->maxExceptions ?? null;
            $job->retryUntil = method_exists($listener, 'retryUntil') ? $listener->retryUntil(...$data) : null;
            $job->shouldBeEncrypted = $listener instanceof ShouldBeEncrypted;
            $job->timeout = $listener->timeout ?? null;
            $job->failOnTimeout = $listener->failOnTimeout ?? false;
            $job->tries = $listener->tries ?? null;

            unset($data[0]);
            $job->through(array_merge(
                method_exists($listener, 'middleware') ? $listener->middleware(...$data) : [],
                $listener->middleware ?? []
            ));
        });
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
     * Gets the raw, unprepared listeners.
     */
    public function getRawListeners(): array
    {
        return $this->listeners->all();
    }

    /**
     * Resolve the subscriber instance.
     *
     * @return object
     */
    protected function resolveSubscriber(object|string $subscriber): mixed
    {
        if (is_string($subscriber)) {
            return $this->container->get($subscriber);
        }

        return $subscriber;
    }

    /**
     * Execute the given callback while deferring events, then dispatch all deferred events.
     */
    public function defer(callable $callback, ?array $events = null): mixed
    {
        $wasDeferring = Context::get('__event.deferring', false);
        $previousDeferredEvents = Context::get('__event.deferred_events', []);
        $previousEventsToDefer = Context::get('__event.events_to_defer', null);

        Context::set('__event.deferring', true);
        Context::set('__event.deferred_events', []);
        Context::set('__event.events_to_defer', $events);

        try {
            $result = $callback();

            Context::set('__event.deferring', false);

            foreach (Context::get('__event.deferred_events', []) as $args) {
                $this->dispatch(...$args);
            }

            return $result;
        } finally {
            Context::set('__event.deferring', $wasDeferring);
            Context::set('__event.deferred_events', $previousDeferredEvents);
            Context::set('__event.events_to_defer', $previousEventsToDefer);
        }
    }

    /**
     * Determine if the given event should be deferred.
     */
    protected function shouldDeferEvent(object|string $event): bool
    {
        if (! Context::get('__event.deferring', false)) {
            return false;
        }

        if (! $eventsToDefer = Context::get('__event.events_to_defer', null)) {
            return true;
        }

        return in_array(
            is_object($event) ? get_class($event) : $event,
            $eventsToDefer
        );
    }
}
