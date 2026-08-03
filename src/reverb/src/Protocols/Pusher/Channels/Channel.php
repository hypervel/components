<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Events\ChannelCreated;
use Hypervel\Reverb\Events\ChannelRemoved;
use Hypervel\Reverb\FailureReporter;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Concerns\SerializesChannels;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SubscriptionResult;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Reverb\Webhooks\DeferredWebhookManager;
use Throwable;

class Channel
{
    use SerializesChannels;

    /**
     * The channel connections.
     */
    protected ChannelConnectionManager $connections;

    /**
     * The number of membership operations currently publishing shared state.
     */
    protected int $membershipOperations = 0;

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
        $result = $this->subscribeToChannel(
            $connection,
            $this->decodeSubscriptionData($data),
            $userId,
        );

        if ($result === null) {
            return;
        }

        $this->afterSubscribed($connection, $result);
    }

    /**
     * Publish and commit a channel subscription.
     */
    protected function subscribeToChannel(
        Connection $connection,
        array $attributes,
        ?string $userId = null,
    ): ?SubscriptionResult {
        ++$this->membershipOperations;

        try {
            if ($this->subscribed($connection)) {
                return null;
            }

            $result = app(SharedState::class)->subscribe(
                $connection->app()->id(),
                $this->name,
                $userId,
            );

            // Shared membership is already published, so the local manager's
            // add contract must commit valid decoded data without throwing.
            $this->connections->add($connection, $attributes);

            return $result;
        } finally {
            --$this->membershipOperations;
            $this->removeIfIdle($connection);
        }
    }

    /**
     * Decode subscription data into connection attributes.
     */
    protected function decodeSubscriptionData(?string $data): array
    {
        return $data
            ? json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR)
            : [];
    }

    /**
     * Complete side effects for a committed subscription.
     */
    protected function afterSubscribed(Connection $connection, SubscriptionResult $result): void
    {
        if ($result->channelOccupied) {
            $this->handleChannelOccupied($connection, app(SharedState::class));
        }

        $this->dispatchSubscriptionCountWebhook($connection, $result);
    }

    /**
     * Handle channel occupied — dispatch events, check disconnect smoothing.
     */
    protected function handleChannelOccupied(Connection $connection, SharedState $sharedState): void
    {
        $app = $connection->app();
        $suppressOccupied = false;

        // Cancel any pending deferred channel_vacated webhook and consume the
        // shared smoothing marker. If either was present (reconnect within
        // the smoothing window), suppress the channel_occupied webhook — the
        // channel was never truly vacated from the consumer's perspective.
        if ($app->hasWebhooks()) {
            $smoothingMs = (int) ($app->webhooks()['disconnect_smoothing_ms'] ?? 3000);

            $cancelledLocally = app(DeferredWebhookManager::class)->cancelChannelVacated(
                $app->id(),
                $this->name,
            );

            // Always consume the shared marker — even if the local timer was
            // cancelled — to prevent stale markers from suppressing a later
            // legitimate channel_occupied.
            $consumedMarker = $smoothingMs > 0
                && $sharedState->clearSmoothingPending($app->id(), $this->name, $smoothingMs);

            $suppressOccupied = $cancelledLocally || $consumedMarker;
        }

        if ($suppressOccupied) {
            return;
        }

        if (app('events')->hasListeners(ChannelCreated::class)) {
            ChannelCreated::dispatch($this);
        }

        app(WebhookDispatcher::class)->dispatch($app, 'channel_occupied', [
            'channel' => $this->name,
        ]);
    }

    /**
     * Unsubscribe from the given channel.
     *
     * Presence channels pass the userId for global refcount tracking.
     */
    public function unsubscribe(Connection $connection, ?string $userId = null): void
    {
        $result = $this->unsubscribeFromChannel($connection, $userId);

        if ($result === null) {
            return;
        }

        $this->afterUnsubscribed($connection, $result);
    }

    /**
     * Publish and commit a channel unsubscription.
     */
    protected function unsubscribeFromChannel(Connection $connection, ?string $userId = null): ?SubscriptionResult
    {
        ++$this->membershipOperations;

        try {
            if (! $this->subscribed($connection)) {
                return null;
            }

            $result = app(SharedState::class)->unsubscribe(
                $connection->app()->id(),
                $this->name,
                $userId,
            );

            $this->connections->remove($connection);

            return $result;
        } finally {
            --$this->membershipOperations;
            $this->removeIfIdle($connection);
        }
    }

    /**
     * Complete side effects for a committed unsubscription.
     */
    protected function afterUnsubscribed(Connection $connection, SubscriptionResult $result): void
    {
        if ($result->channelVacated) {
            $this->handleChannelVacated($connection);
        }

        $this->dispatchSubscriptionCountWebhook($connection, $result);
    }

    /**
     * Handle channel vacated — dispatch events and clean up shared locks.
     */
    protected function handleChannelVacated(Connection $connection): void
    {
        if (app('events')->hasListeners(ChannelRemoved::class)) {
            ChannelRemoved::dispatch($this);
        }

        // Clean up webhook lock rows to prevent stale rows accumulating
        // in the Swoole Table over worker lifetime.
        $sharedState = app(SharedState::class);
        $sharedState->clearSubscriptionCountLock($connection->app()->id(), $this->name);

        if ($this instanceof CacheChannel) {
            $sharedState->clearCacheMissLock($connection->app()->id(), $this->name);
        }

        $app = $connection->app();

        if (! $app->hasWebhooks()) {
            return;
        }

        $delayMs = (int) ($app->webhooks()['disconnect_smoothing_ms'] ?? 3000);
        $manager = app(DeferredWebhookManager::class);

        if ($delayMs > 0 && $connection->isDisconnecting() && ! $manager->isDraining()) {
            app(SharedState::class)->setSmoothingPending($app->id(), $this->name, $delayMs);
            $manager->deferChannelVacated($app, $this->name, $delayMs / 1000.0, $delayMs);
        } else {
            app(WebhookDispatcher::class)->dispatch($app, 'channel_vacated', [
                'channel' => $this->name,
            ]);
        }
    }

    /**
     * Dispatch the subscription_count webhook if enabled and not throttled.
     *
     * Fires on every subscribe/unsubscribe for non-presence channels.
     * Throttled to once per 5 seconds for channels with >100 subscribers.
     */
    protected function dispatchSubscriptionCountWebhook(
        Connection $connection,
        SubscriptionResult $result,
    ): void {
        if ($this instanceof PresenceChannel || $this instanceof PresenceCacheChannel) {
            return;
        }

        $app = $connection->app();
        $webhooks = $app->webhooks();

        if (! $app->hasWebhooks() || ! ($webhooks['subscription_count'] ?? false)) {
            return;
        }

        $count = $result->subscriptionCount;

        if ($count > 100 && ! app(SharedState::class)->trySubscriptionCountLock($app->id(), $this->name)) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch($app, 'subscription_count', [
            'channel' => $this->name,
            'subscription_count' => $count,
        ]);
    }

    /**
     * Determine if the connection is subscribed to the channel.
     */
    public function subscribed(Connection $connection): bool
    {
        return $this->connections->find($connection) !== null;
    }

    /**
     * Remove this exact channel when no local membership work remains.
     */
    protected function removeIfIdle(Connection $connection): void
    {
        if ($this->membershipOperations !== 0 || ! $this->connections->isEmpty()) {
            return;
        }

        app(ChannelManager::class)->for($connection->app())->remove($this);
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

        $message = json_encode($payload, JSON_THROW_ON_ERROR);

        Log::info('Broadcasting To', $this->name());
        Log::message($message);

        $this->sendToConnections($message, $except);
    }

    /**
     * Send a broadcast to all connections.
     */
    public function broadcastToAll(array $payload): void
    {
        $message = json_encode($payload, JSON_THROW_ON_ERROR);

        Log::info('Broadcasting To', $this->name());
        Log::message($message);

        $this->sendToConnections($message);
    }

    /**
     * Send a message to every selected local connection.
     */
    protected function sendToConnections(string $message, ?Connection $except = null): void
    {
        $exceptId = $except?->id();
        $exception = null;

        foreach ($this->connections() as $connection) {
            if ($exceptId !== null && $connection->id() === $exceptId) {
                continue;
            }

            try {
                $connection->send($message);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            FailureReporter::report($exception);
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
