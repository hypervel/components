<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
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
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelListener;
use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Event\NullDispatcher;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use ReflectionClass;

trait HasEvents
{
    /**
     * Mapping of event names to their corresponding event classes.
     *
     * @var array<string, class-string<ModelEvent>>
     */
    protected static array $modelEventClasses = [
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
     * The event map for the model.
     *
     * Allows for object-based events for native Eloquent events.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [];

    /**
     * User exposed observable events.
     *
     * These are extra user-defined events observers may subscribe to.
     *
     * @var string[]
     */
    protected $observables = [];

    /**
     * Boot the has event trait for a model.
     */
    public static function bootHasEvents(): void
    {
        static::whenBooted(fn () => static::observe(static::resolveObserveAttributes()));
    }

    /**
     * Resolve the observe class names from the attributes.
     *
     * @return array<int, class-string>
     */
    public static function resolveObserveAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $isEloquentGrandchild = is_subclass_of(static::class, Model::class)
            && get_parent_class(static::class) !== Model::class;

        return (new Collection($reflectionClass->getAttributes(ObservedBy::class)))
            ->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isEloquentGrandchild, function (Collection $attributes) {
                return (new Collection(get_parent_class(static::class)::resolveObserveAttributes()))
                    ->merge($attributes);
            })
            ->all();
    }

    /**
     * Register observers with the model.
     *
     * @param object|array<class-string>|class-string $classes
     */
    public static function observe(object|array|string $classes): void
    {
        $listener = static::getModelListener();

        foreach (Arr::wrap($classes) as $class) {
            $listener->registerObserver(static::class, $class);
        }
    }

    /**
     * Get the observable event names.
     *
     * @return string[]
     */
    public function getObservableEvents(): array
    {
        return array_merge(
            [
                'retrieved', 'creating', 'created', 'updating', 'updated',
                'saving', 'saved', 'restoring', 'restored', 'replicating',
                'trashed', 'deleting', 'deleted', 'forceDeleting', 'forceDeleted',
            ],
            $this->observables
        );
    }

    /**
     * Set the observable event names.
     *
     * @param string[] $observables
     * @return $this
     */
    public function setObservableEvents(array $observables): static
    {
        $this->observables = $observables;

        return $this;
    }

    /**
     * Add an observable event name.
     *
     * @param string|string[] $observables
     */
    public function addObservableEvents(array|string $observables): void
    {
        $this->observables = array_unique(array_merge(
            $this->observables,
            is_array($observables) ? $observables : func_get_args()
        ));
    }

    /**
     * Remove an observable event name.
     *
     * @param string|string[] $observables
     */
    public function removeObservableEvents(array|string $observables): void
    {
        $this->observables = array_diff(
            $this->observables,
            is_array($observables) ? $observables : func_get_args()
        );
    }

    /**
     * Get the event class for the given event name.
     *
     * @return class-string<ModelEvent>|null
     */
    protected static function getModelEventClass(string $event): ?string
    {
        return static::$modelEventClasses[$event] ?? null;
    }

    /**
     * Register a model event callback.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    protected static function registerModelEvent(string $event, mixed $callback): void
    {
        $eventClass = static::getModelEventClass($event);

        if ($eventClass !== null) {
            // Standard model event - register via ModelListener for efficient routing
            static::getModelListener()->register(static::class, $event, $callback);
        } else {
            // Custom observable event - use string-based events via dispatcher
            if (isset(static::$dispatcher)) {
                static::$dispatcher->listen("eloquent.{$event}: " . static::class, $callback);
            }
        }
    }

    /**
     * Fire the given event for the model.
     *
     * @return mixed
     */
    protected function fireModelEvent(string $event, bool $halt = true): mixed
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        $method = $halt ? 'until' : 'dispatch';

        // First, check if user has defined a custom event class in $dispatchesEvents
        $result = $this->filterModelEventResults(
            $this->fireCustomModelEvent($event, $method)
        );

        if ($result === false) {
            return false;
        }

        if (! empty($result)) {
            return $result;
        }

        // Fire our class-based event (ModelListener will route to callbacks)
        $eventClass = static::getModelEventClass($event);

        if ($eventClass !== null) {
            $eventInstance = new $eventClass($this);
            return static::$dispatcher->{$method}($eventInstance);
        }

        // Fallback to string-based for custom observable events
        return static::$dispatcher->{$method}(
            "eloquent.{$event}: " . static::class,
            $this
        );
    }

    /**
     * Fire a custom model event for the given event.
     *
     * @param 'until'|'dispatch' $method
     * @return mixed
     */
    protected function fireCustomModelEvent(string $event, string $method): mixed
    {
        if (! isset($this->dispatchesEvents[$event])) {
            return null;
        }

        $result = static::$dispatcher->{$method}(new $this->dispatchesEvents[$event]($this));

        if (! is_null($result)) {
            return $result;
        }

        return null;
    }

    /**
     * Filter the model event results.
     *
     * @return mixed
     */
    protected function filterModelEventResults(mixed $result): mixed
    {
        if (is_array($result)) {
            $result = array_filter($result, fn ($response) => ! is_null($response));
        }

        return $result;
    }

    /**
     * Register a retrieved model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function retrieved(mixed $callback): void
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /**
     * Register a saving model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function saving(mixed $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a saved model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function saved(mixed $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register an updating model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function updating(mixed $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register an updated model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function updated(mixed $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a creating model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function creating(mixed $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a created model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function created(mixed $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register a replicating model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function replicating(mixed $callback): void
    {
        static::registerModelEvent('replicating', $callback);
    }

    /**
     * Register a deleting model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function deleting(mixed $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a deleted model event with the dispatcher.
     *
     * @param \Hypervel\Event\QueuedClosure|callable|array|class-string $callback
     */
    public static function deleted(mixed $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Remove all the event listeners for the model.
     */
    public static function flushEventListeners(): void
    {
        if (! isset(static::$dispatcher)) {
            return;
        }

        $instance = new static();

        // Clear from ModelListener
        static::getModelListener()->clear(static::class);

        // Clear custom observable events from dispatcher
        foreach ($instance->observables as $event) {
            static::$dispatcher->forget("eloquent.{$event}: " . static::class);
        }

        // Clear custom dispatchesEvents from dispatcher
        foreach ($instance->dispatchesEvents as $event) {
            static::$dispatcher->forget($event);
        }
    }

    /**
     * Get the event map for the model.
     *
     * @return array<string, class-string>
     */
    public function dispatchesEvents(): array
    {
        return $this->dispatchesEvents;
    }

    /**
     * Get the event dispatcher instance.
     */
    public static function getEventDispatcher(): ?Dispatcher
    {
        return static::$dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     */
    public static function setEventDispatcher(Dispatcher $dispatcher): void
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Unset the event dispatcher for models.
     */
    public static function unsetEventDispatcher(): void
    {
        static::$dispatcher = null;
    }

    /**
     * Execute a callback without firing any model events for any model type.
     *
     * @return mixed
     */
    public static function withoutEvents(callable $callback): mixed
    {
        $dispatcher = static::getEventDispatcher();

        if ($dispatcher) {
            static::setEventDispatcher(new NullDispatcher($dispatcher));
        }

        try {
            return $callback();
        } finally {
            if ($dispatcher) {
                static::setEventDispatcher($dispatcher);
            }
        }
    }

    /**
     * Get the model listener instance.
     */
    protected static function getModelListener(): ModelListener
    {
        return ApplicationContext::getContainer()->get(ModelListener::class);
    }
}
