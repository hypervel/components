<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Database\Eloquent\Events\Booted;
use Hypervel\Database\Eloquent\Events\Booting;
use Hypervel\Database\Eloquent\Events\Created;
use Hypervel\Database\Eloquent\Events\Creating;
use Hypervel\Database\Eloquent\Events\Deleted;
use Hypervel\Database\Eloquent\Events\Deleting;
use Hypervel\Database\Eloquent\Events\ForceDeleted;
use Hypervel\Database\Eloquent\Events\ForceDeleting;
use Hypervel\Database\Eloquent\Events\ModelEvent;
use Hypervel\Database\Eloquent\Events\Replicating;
use Hypervel\Database\Eloquent\Events\Restored;
use Hypervel\Database\Eloquent\Events\Restoring;
use Hypervel\Database\Eloquent\Events\Retrieved;
use Hypervel\Database\Eloquent\Events\Saved;
use Hypervel\Database\Eloquent\Events\Saving;
use Hypervel\Database\Eloquent\Events\Trashed;
use Hypervel\Database\Eloquent\Events\Updated;
use Hypervel\Database\Eloquent\Events\Updating;
use Hypervel\Event\Contracts\Dispatcher;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Routes model events to per-model callbacks efficiently.
 *
 * Registers one listener per event type with the dispatcher, then routes
 * to the correct model's callbacks via internal hash map lookup (O(1)).
 */
class ModelListener
{
    /**
     * The model events mapped to their event classes.
     *
     * @var array<string, class-string<ModelEvent>>
     */
    protected const MODEL_EVENTS = [
        'booting' => Booting::class,
        'booted' => Booted::class,
        'retrieved' => Retrieved::class,
        'creating' => Creating::class,
        'created' => Created::class,
        'updating' => Updating::class,
        'updated' => Updated::class,
        'saving' => Saving::class,
        'saved' => Saved::class,
        'deleting' => Deleting::class,
        'deleted' => Deleted::class,
        'restoring' => Restoring::class,
        'restored' => Restored::class,
        'trashed' => Trashed::class,
        'forceDeleting' => ForceDeleting::class,
        'forceDeleted' => ForceDeleted::class,
        'replicating' => Replicating::class,
    ];

    /**
     * Event types that have been bootstrapped with the dispatcher.
     *
     * @var array<class-string<ModelEvent>, bool>
     */
    protected array $bootstrappedEvents = [];

    /**
     * Registered callbacks keyed by model class and event name.
     *
     * @var array<class-string<Model>, array<string, list<callable>>>
     */
    protected array $callbacks = [];

    /**
     * Registered observers keyed by model class.
     *
     * @var array<class-string<Model>, array<string, list<class-string>>>
     */
    protected array $observers = [];

    public function __construct(
        protected ContainerInterface $container,
        protected Dispatcher $dispatcher
    ) {
    }

    /**
     * Bootstrap the given model event type with the dispatcher.
     *
     * @param class-string<ModelEvent> $eventClass
     */
    protected function bootstrapEvent(string $eventClass): void
    {
        if ($this->bootstrappedEvents[$eventClass] ?? false) {
            return;
        }

        $this->dispatcher->listen($eventClass, [$this, 'handleEvent']);

        $this->bootstrappedEvents[$eventClass] = true;
    }

    /**
     * Register a callback to be executed when a model event is fired.
     *
     * @param class-string<Model> $modelClass
     * @throws InvalidArgumentException
     */
    public function register(string $modelClass, string $event, callable $callback): void
    {
        $this->validateModelClass($modelClass);

        $eventClass = static::MODEL_EVENTS[$event] ?? null;

        if ($eventClass === null) {
            throw new InvalidArgumentException("Event [{$event}] is not a valid Eloquent event.");
        }

        $this->bootstrapEvent($eventClass);

        $this->callbacks[$modelClass][$event][] = $callback;
    }

    /**
     * Validate that the given class is a valid model class.
     *
     * @param class-string $modelClass
     * @throws InvalidArgumentException
     */
    protected function validateModelClass(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            throw new InvalidArgumentException("Unable to find model class: {$modelClass}");
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("Class [{$modelClass}] must extend Model.");
        }
    }

    /**
     * Register an observer for a model.
     *
     * @param class-string<Model> $modelClass
     * @param class-string|object $observer
     * @throws InvalidArgumentException
     */
    public function registerObserver(string $modelClass, string|object $observer): void
    {
        $observerClass = is_object($observer) ? $observer::class : $observer;

        if (! class_exists($observerClass)) {
            throw new InvalidArgumentException("Unable to find observer: {$observerClass}");
        }

        $observerInstance = is_object($observer)
            ? $observer
            : $this->container->get($observerClass);

        foreach (static::MODEL_EVENTS as $event => $eventClass) {
            if (! method_exists($observerInstance, $event)) {
                continue;
            }

            $this->register($modelClass, $event, [$observerInstance, $event]);

            $this->observers[$modelClass][$event][] = $observerClass;
        }
    }

    /**
     * Remove all callbacks for a model, or a specific event.
     *
     * @param class-string<Model> $modelClass
     */
    public function clear(string $modelClass, ?string $event = null): void
    {
        if ($event === null) {
            unset($this->callbacks[$modelClass], $this->observers[$modelClass]);
            return;
        }

        unset($this->callbacks[$modelClass][$event], $this->observers[$modelClass][$event]);
    }

    /**
     * Handle a model event and execute the registered callbacks.
     */
    public function handleEvent(ModelEvent $event): mixed
    {
        $modelClass = $event->model::class;
        $callbacks = $this->callbacks[$modelClass][$event->method] ?? [];

        foreach ($callbacks as $callback) {
            $result = $callback($event->model);

            // If callback returns false, stop propagation
            if ($result === false) {
                return false;
            }
        }

        return null;
    }

    /**
     * Get callbacks for a model, optionally filtered by event.
     *
     * @param class-string<Model> $modelClass
     * @return ($event is null ? array<string, list<callable>> : list<callable>)
     */
    public function getCallbacks(string $modelClass, ?string $event = null): array
    {
        if ($event !== null) {
            return $this->callbacks[$modelClass][$event] ?? [];
        }

        return $this->callbacks[$modelClass] ?? [];
    }

    /**
     * Get registered observers for a model, optionally filtered by event.
     *
     * @param class-string<Model> $modelClass
     * @return ($event is null ? array<string, list<class-string>> : list<class-string>)
     */
    public function getObservers(string $modelClass, ?string $event = null): array
    {
        if ($event !== null) {
            return $this->observers[$modelClass][$event] ?? [];
        }

        return $this->observers[$modelClass] ?? [];
    }

    /**
     * Get all available model events.
     *
     * @return array<string, class-string<ModelEvent>>
     */
    public function getModelEvents(): array
    {
        return static::MODEL_EVENTS;
    }

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<ModelEvent>|null
     */
    public function getEventClass(string $event): ?string
    {
        return static::MODEL_EVENTS[$event] ?? null;
    }
}
