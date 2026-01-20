<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Database\Model\Events;
use Hyperf\Database\Model\Events\Event;
use Hyperf\Database\Model\Model;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;

class ModelListener
{
    /**
     * The model events that should be registered.
     */
    protected const MODEL_EVENTS = [
        'booting' => Events\Booting::class,
        'booted' => Events\Booted::class,
        'retrieved' => Events\Retrieved::class,
        'creating' => Events\Creating::class,
        'created' => Events\Created::class,
        'updating' => Events\Updating::class,
        'updated' => Events\Updated::class,
        'saving' => Events\Saving::class,
        'saved' => Events\Saved::class,
        'deleting' => Events\Deleting::class,
        'deleted' => Events\Deleted::class,
        'restoring' => Events\Restoring::class,
        'restored' => Events\Restored::class,
        'forceDeleting' => Events\ForceDeleting::class,
        'forceDeleted' => Events\ForceDeleted::class,
    ];

    /**
     * Indicates if the manager has been bootstrapped.
     */
    protected array $bootstrappedEvents = [];

    /*
    * The registered callbacks.
    */
    protected array $callbacks = [];

    public function __construct(
        protected EventDispatcherInterface $dispatcher
    ) {
    }

    /**
     * Bootstrap the given model events.
     */
    protected function bootstrapEvent(string $eventClass): void
    {
        if ($this->bootstrappedEvents[$eventClass] ?? false) {
            return;
        }

        /* @phpstan-ignore-next-line */
        $this->dispatcher->listen(
            $eventClass,
            [$this, 'handleEvent']
        );

        $this->bootstrappedEvents[$eventClass] = true;
    }

    /**
     * Register a callback to be executed when a model event is fired.
     */
    public function register(Model|string $model, string $event, callable $callback): void
    {
        if (is_string($model)) {
            $this->validateModelClass($model);
        }

        $modelClass = $this->getModelClass($model);
        if (! $eventClass = (static::MODEL_EVENTS[$event] ?? null)) {
            throw new InvalidArgumentException("Event [{$event}] is not a valid Eloquent event.");
        }

        $this->bootstrapEvent($eventClass);

        $this->callbacks[$modelClass][$event][] = $callback;
    }

    /**
     * Remove all of the callbacks for a model event.
     */
    public function clear(Model|string $model, ?string $event = null): void
    {
        $modelClass = $this->getModelClass($model);
        if (! $event) {
            unset($this->callbacks[$modelClass]);
            return;
        }

        unset($this->callbacks[$modelClass][$event]);
    }

    /**
     * Execute callbacks from the given model event.
     */
    public function handleEvent(Event $event): void
    {
        $callbacks = $this->getCallbacks(
            $model = $event->getModel(),
            $event->getMethod()
        );

        foreach ($callbacks as $callback) {
            $callback($model);
        }
    }

    /**
     * Get callbacks by the model and event.
     */
    public function getCallbacks(Model|string $model, ?string $event = null): array
    {
        $modelClass = $this->getModelClass($model);
        if ($event) {
            return $this->callbacks[$modelClass][$event] ?? [];
        }

        return $this->callbacks[$modelClass] ?? [];
    }

    /**
     * Get all available model events.
     */
    public function getModelEvents(): array
    {
        return static::MODEL_EVENTS;
    }

    protected function validateModelClass(string $modelClass): void
    {
        if (! class_exists($modelClass)) {
            throw new InvalidArgumentException('Unable to find model class: ' . $modelClass);
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("Model class must extends `{$modelClass}`");
        }
    }

    protected function getModelClass(Model|string $model): string
    {
        return is_string($model)
            ? $model
            : get_class($model);
    }
}
