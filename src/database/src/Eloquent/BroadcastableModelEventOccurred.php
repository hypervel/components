<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Support\Collection;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Broadcasting\InteractsWithSockets;
use Hypervel\Broadcasting\PrivateChannel;
use Hypervel\Queue\SerializesModels;

class BroadcastableModelEventOccurred implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The channels that the event should be broadcast on.
     */
    protected array $channels = [];

    /**
     * The queue connection that should be used to queue the broadcast job.
     */
    public ?string $connection = null;

    /**
     * The queue that should be used to queue the broadcast job.
     */
    public ?string $queue = null;

    /**
     * Indicates whether the job should be dispatched after all database transactions have committed.
     */
    public bool $afterCommit = false;

    /**
     * Create a new event instance.
     *
     * @param Model $model The model instance corresponding to the event.
     * @param string $event The event name (created, updated, etc.).
     */
    public function __construct(
        public Model $model,
        public string $event
    ) {
    }

    /**
     * The channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        $channels = empty($this->channels)
            ? ($this->model->broadcastOn($this->event) ?: []) /* @phpstan-ignore-line */
            : $this->channels;

        return (new Collection($channels))
            ->map(fn ($channel) => $channel instanceof Model ? new PrivateChannel($channel) : $channel) /* @phpstan-ignore-line */
            ->all();
    }

    /**
     * The name the event should broadcast as.
     */
    public function broadcastAs(): string
    {
        $default = class_basename($this->model) . ucfirst($this->event);

        return method_exists($this->model, 'broadcastAs')
            ? ($this->model->broadcastAs($this->event) ?: $default)
            : $default;
    }

    /**
     * Get the data that should be sent with the broadcasted event.
     */
    public function broadcastWith(): ?array
    {
        return method_exists($this->model, 'broadcastWith')
            ? $this->model->broadcastWith($this->event)
            : null;
    }

    /**
     * Manually specify the channels the event should broadcast on.
     */
    public function onChannels(array $channels): static
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * Determine if the event should be broadcast synchronously.
     */
    public function shouldBroadcastNow(): bool
    {
        return $this->event === 'deleted'
            && ! method_exists($this->model, 'bootSoftDeletes');
    }

    /**
     * Get the event name.
     */
    public function event(): string
    {
        return $this->event;
    }
}
