<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Broadcasting\Contracts\Factory as BroadcastFactory;
use Hypervel\Support\Arr;

trait BroadcastsEvents
{
    /**
     * Indicates if the model is currently broadcasting.
     */
    protected static bool $isBroadcasting = true;

    /**
     * Boot the event broadcasting trait.
     */
    public static function bootBroadcastsEvents(): void
    {
        static::created(function ($model) {
            $model->broadcastCreated();
        });

        static::updated(function ($model) {
            $model->broadcastUpdated();
        });

        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::softDeleted(function ($model) {
                $model->broadcastTrashed();
            });

            static::restored(function ($model) {
                $model->broadcastRestored();
            });
        }

        static::deleted(function ($model) {
            $model->broadcastDeleted();
        });
    }

    /**
     * Broadcast that the model was created.
     *
     * @param  \Hypervel\Broadcasting\Channel|\Hypervel\Broadcasting\Contracts\HasBroadcastChannel|array|null  $channels
     * @return \Hypervel\Broadcasting\PendingBroadcast|null
     */
    public function broadcastCreated($channels = null)
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('created'), 'created', $channels
        );
    }

    /**
     * Broadcast that the model was updated.
     *
     * @param  \Hypervel\Broadcasting\Channel|\Hypervel\Broadcasting\Contracts\HasBroadcastChannel|array|null  $channels
     * @return \Hypervel\Broadcasting\PendingBroadcast|null
     */
    public function broadcastUpdated($channels = null)
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('updated'), 'updated', $channels
        );
    }

    /**
     * Broadcast that the model was trashed.
     *
     * @param  \Hypervel\Broadcasting\Channel|\Hypervel\Broadcasting\Contracts\HasBroadcastChannel|array|null  $channels
     * @return \Hypervel\Broadcasting\PendingBroadcast|null
     */
    public function broadcastTrashed($channels = null)
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('trashed'), 'trashed', $channels
        );
    }

    /**
     * Broadcast that the model was restored.
     *
     * @param  \Hypervel\Broadcasting\Channel|\Hypervel\Broadcasting\Contracts\HasBroadcastChannel|array|null  $channels
     * @return \Hypervel\Broadcasting\PendingBroadcast|null
     */
    public function broadcastRestored($channels = null)
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('restored'), 'restored', $channels
        );
    }

    /**
     * Broadcast that the model was deleted.
     *
     * @param  \Hypervel\Broadcasting\Channel|\Hypervel\Broadcasting\Contracts\HasBroadcastChannel|array|null  $channels
     * @return \Hypervel\Broadcasting\PendingBroadcast|null
     */
    public function broadcastDeleted($channels = null)
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('deleted'), 'deleted', $channels
        );
    }

    /**
     * Broadcast the given event instance if channels are configured for the model event.
     *
     * @return \Hypervel\Broadcasting\PendingBroadcast|null
     */
    protected function broadcastIfBroadcastChannelsExistForEvent(mixed $instance, string $event, mixed $channels = null)
    {
        if (! static::$isBroadcasting) {
            return null;
        }

        if (! empty($this->broadcastOn($event)) || ! empty($channels)) {
            return app(BroadcastFactory::class)->event($instance->onChannels(Arr::wrap($channels)));
        }

        return null;
    }

    /**
     * Create a new broadcastable model event event.
     */
    public function newBroadcastableModelEvent(string $event): mixed
    {
        return tap($this->newBroadcastableEvent($event), function ($event) {
            $event->connection = property_exists($this, 'broadcastConnection')
                ? $this->broadcastConnection
                : $this->broadcastConnection();

            $event->queue = property_exists($this, 'broadcastQueue')
                ? $this->broadcastQueue
                : $this->broadcastQueue();

            $event->afterCommit = property_exists($this, 'broadcastAfterCommit')
                ? $this->broadcastAfterCommit
                : $this->broadcastAfterCommit();
        });
    }

    /**
     * Create a new broadcastable model event for the model.
     */
    protected function newBroadcastableEvent(string $event): BroadcastableModelEventOccurred
    {
        return new BroadcastableModelEventOccurred($this, $event);
    }

    /**
     * Get the channels that model events should broadcast on.
     *
     * @return \Hypervel\Broadcasting\Channel|array
     */
    public function broadcastOn(string $event)
    {
        return [$this];
    }

    /**
     * Get the queue connection that should be used to broadcast model events.
     */
    public function broadcastConnection(): ?string
    {
        return null;
    }

    /**
     * Get the queue that should be used to broadcast model events.
     */
    public function broadcastQueue(): ?string
    {
        return null;
    }

    /**
     * Determine if the model event broadcast queued job should be dispatched after all transactions are committed.
     */
    public function broadcastAfterCommit(): bool
    {
        return false;
    }
}
