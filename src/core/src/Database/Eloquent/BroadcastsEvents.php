<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Collection\Arr;
use Hyperf\Context\ApplicationContext;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\Contracts\Factory as BroadcastFactory;
use Hypervel\Broadcasting\Contracts\HasBroadcastChannel;
use Hypervel\Broadcasting\PendingBroadcast;

use function Hyperf\Tappable\tap;

trait BroadcastsEvents
{
    protected static $isBroadcasting = true;

    /**
     * Boot the event broadcasting trait.
     */
    public static function bootBroadcastsEvents(): void
    {
        static::registerCallback(
            'created',
            fn ($model) => $model->broadcastCreated()
        );

        static::registerCallback(
            'updated',
            fn ($model) => $model->broadcastUpdated()
        );

        static::registerCallback(
            'deleted',
            fn ($model) => $model->broadcastDeleted()
        );
    }

    /**
     * Broadcast that the model was created.
     */
    public function broadcastCreated(array|Channel|HasBroadcastChannel|null $channels = null): ?PendingBroadcast
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
    public function broadcastUpdated(array|Channel|HasBroadcastChannel|null $channels = null): ?PendingBroadcast
    {
        return $this->broadcastIfBroadcastChannelsExistForEvent(
            $this->newBroadcastableModelEvent('updated'),
            'updated',
            $channels
        );
    }

    /**
     * Broadcast that the model was deleted.
     */
    public function broadcastDeleted(array|Channel|HasBroadcastChannel|null $channels = null): ?PendingBroadcast
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
    protected function broadcastIfBroadcastChannelsExistForEvent(mixed $instance, string $event, mixed $channels = null): ?PendingBroadcast
    {
        if (! static::$isBroadcasting) {
            return null;
        }

        if (! empty($this->broadcastOn($event)) || ! empty($channels)) {
            return ApplicationContext::getContainer()
                ->get(BroadcastFactory::class)
                ->event($instance->onChannels(Arr::wrap($channels)));
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
     */
    public function broadcastOn(string $event): array|Channel
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
