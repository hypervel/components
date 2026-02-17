<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastFactory;
use Hypervel\Contracts\Broadcasting\HasBroadcastChannel;
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
     */
    public function broadcastCreated(Channel|HasBroadcastChannel|array|null $channels = null): ?PendingBroadcast
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('created'),
            'created',
            $channels
        );
    }

    /**
     * Broadcast that the model was updated.
     */
    public function broadcastUpdated(Channel|HasBroadcastChannel|array|null $channels = null): ?PendingBroadcast
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('updated'),
            'updated',
            $channels
        );
    }

    /**
     * Broadcast that the model was trashed.
     */
    public function broadcastTrashed(Channel|HasBroadcastChannel|array|null $channels = null): ?PendingBroadcast
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('trashed'),
            'trashed',
            $channels
        );
    }

    /**
     * Broadcast that the model was restored.
     */
    public function broadcastRestored(Channel|HasBroadcastChannel|array|null $channels = null): ?PendingBroadcast
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('restored'),
            'restored',
            $channels
        );
    }

    /**
     * Broadcast that the model was deleted.
     */
    public function broadcastDeleted(Channel|HasBroadcastChannel|array|null $channels = null): ?PendingBroadcast
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('deleted'),
            'deleted',
            $channels
        );
    }

    /**
     * Broadcast the given event instance if channels are configured for the model event.
     */
    protected function broadcastIfBroadcastChannelsExistForEvent(
        BroadcastableModelEventOccurred $instance,
        string $event,
        Channel|HasBroadcastChannel|array|null $channels = null,
    ): ?PendingBroadcast {
        if (! static::isBroadcasting()) {
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
    public function newBroadcastableModelEvent(string $event): BroadcastableModelEventOccurred
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
     */
    public function broadcastOn(string $event): Channel|array
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
