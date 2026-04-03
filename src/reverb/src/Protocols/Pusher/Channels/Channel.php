<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Events\ChannelCreated;
use Hypervel\Reverb\Events\ChannelRemoved;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Concerns\SerializesChannels;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SubscriptionResult;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;

class Channel
{
    use SerializesChannels;

    /**
     * The channel connections.
     */
    protected ChannelConnectionManager $connections;

    /**
     * The result from the most recent shared state subscribe/unsubscribe call.
     */
    protected SubscriptionResult $lastSubscriptionResult;

    /**
     * Create a new channel instance.
     */
    public function __construct(protected string $name)
    {
        $this->connections = app(ChannelConnectionManager::class)->for($this->name);
    }

    /**
     * Get the channel name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get all connections for the channel.
     *
     * @return array<string, ChannelConnection>
     */
    public function connections(): array
    {
        return $this->connections->all();
    }

    /**
     * Find a connection.
     */
    public function find(Connection $connection): ?ChannelConnection
    {
        return $this->connections->find($connection);
    }

    /**
     * Find a connection by its ID.
     */
    public function findById(string $id): ?ChannelConnection
    {
        return $this->connections->findById($id);
    }

    /**
     * Subscribe to the given channel.
     *
     * Presence channels pass the userId for global refcount tracking.
     */
    public function subscribe(Connection $connection, ?string $auth = null, ?string $data = null, ?string $userId = null): void
    {
        $this->connections->add($connection, $data ? json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR) : []);

        $this->lastSubscriptionResult = app(SharedState::class)->subscribe(
            $connection->app()->id(),
            $this->name,
            $userId,
        );

        if ($this->lastSubscriptionResult->channelOccupied) {
            if (app('events')->hasListeners(ChannelCreated::class)) {
                ChannelCreated::dispatch($this);
            }

            app(WebhookDispatcher::class)->dispatch($connection->app(), 'channel_occupied', [
                'channel' => $this->name,
            ]);
        }
    }

    /**
     * Get the result from the most recent subscribe/unsubscribe shared state call.
     *
     * Used by presence channel traits to check memberAdded/memberRemoved.
     */
    protected function lastSubscriptionResult(): SubscriptionResult
    {
        return $this->lastSubscriptionResult;
    }

    /**
     * Unsubscribe from the given channel.
     *
     * Presence channels pass the userId for global refcount tracking.
     */
    public function unsubscribe(Connection $connection, ?string $userId = null): void
    {
        $this->connections->remove($connection);

        $this->lastSubscriptionResult = app(SharedState::class)->unsubscribe(
            $connection->app()->id(),
            $this->name,
            $userId,
        );

        if ($this->lastSubscriptionResult->channelVacated) {
            app(ChannelManager::class)->for($connection->app())->remove($this);

            if (app('events')->hasListeners(ChannelRemoved::class)) {
                ChannelRemoved::dispatch($this);
            }

            app(WebhookDispatcher::class)->dispatch($connection->app(), 'channel_vacated', [
                'channel' => $this->name,
            ]);
        }
    }

    /**
     * Determine if the connection is subscribed to the channel.
     */
    public function subscribed(Connection $connection): bool
    {
        return $this->connections->find($connection) !== null;
    }

    /**
     * Send a message to all connections subscribed to the channel.
     */
    public function broadcast(array $payload, ?Connection $except = null): void
    {
        if ($except === null) {
            $this->broadcastToAll($payload);

            return;
        }

        $message = json_encode($payload);

        Log::info('Broadcasting To', $this->name());
        Log::message($message);

        foreach ($this->connections() as $connection) {
            if ($except->id() === $connection->id()) {
                continue;
            }

            $connection->send($message);
        }
    }

    /**
     * Send a broadcast to all connections.
     */
    public function broadcastToAll(array $payload): void
    {
        $message = json_encode($payload);

        Log::info('Broadcasting To', $this->name());
        Log::message($message);

        foreach ($this->connections() as $connection) {
            $connection->send($message);
        }
    }

    /**
     * Broadcast a message triggered from an internal source.
     */
    public function broadcastInternally(array $payload, ?Connection $except = null): void
    {
        $this->broadcast($payload, $except);
    }

    /**
     * Get the data associated with the channel.
     */
    public function data(): array
    {
        return [];
    }
}
