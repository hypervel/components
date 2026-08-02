<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\InvalidMessageFormat;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\Contracts\WebhookDispatcher;
use Hypervel\Support\Str;
use JsonException;

class EventHandler
{
    /**
     * Create a new Pusher event instance.
     */
    public function __construct(protected ChannelManager $channels)
    {
    }

    /**
     * Handle an incoming Pusher event.
     */
    public function handle(Connection $connection, string $event, array $payload = []): void
    {
        $event = Str::after($event, 'pusher:');

        if (in_array($event, ['subscribe', 'unsubscribe'], true)
            && ! is_string($payload['channel'] ?? null)) {
            throw new InvalidMessageFormat('Invalid subscription channel');
        }

        if ($event === 'subscribe'
            && isset($payload['auth'])
            && ! is_string($payload['auth'])) {
            throw new InvalidMessageFormat('Invalid subscription authentication');
        }

        if ($event === 'subscribe'
            && isset($payload['channel_data'])
            && ! is_string($payload['channel_data'])) {
            throw new InvalidMessageFormat('Invalid subscription channel data');
        }

        match ($event) {
            'connection_established' => $this->acknowledge($connection),
            'subscribe' => $this->subscribe(
                $connection,
                $payload['channel'],
                $payload['auth'] ?? null,
                $payload['channel_data'] ?? null
            ),
            'unsubscribe' => $this->unsubscribe($connection, $payload['channel']),
            'ping' => $this->pong($connection),
            'pong' => $connection->touch(),
            default => throw new InvalidMessageFormat('Unknown Pusher event: ' . $event),
        };
    }

    /**
     * Acknowledge the connection.
     */
    public function acknowledge(Connection $connection): void
    {
        $this->send($connection, 'connection_established', [
            'socket_id' => $connection->id(),
            'activity_timeout' => $connection->app()->activityTimeout(),
        ]);
    }

    /**
     * Subscribe to the given channel.
     */
    public function subscribe(Connection $connection, string $channel, ?string $auth = null, ?string $data = null): void
    {
        if ($data !== null) {
            try {
                $decodedData = json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidMessageFormat('Invalid subscription data', previous: $exception);
            }

            if (! is_array($decodedData)) {
                throw new InvalidMessageFormat('Subscription data must be a JSON object');
            }
        }

        $channels = $this->channels->for($connection->app());
        $channelExisted = $channels->exists($channel);
        $channel = $channels->findOrCreate($channel);

        try {
            $channel->subscribe($connection, $auth, $data);
        } catch (ConnectionUnauthorized $exception) {
            if (! $channelExisted && $channel->connections() === []) {
                $channels->remove($channel);
            }

            throw $exception;
        }

        $this->afterSubscribe($channel, $connection);
    }

    /**
     * Carry out any actions that should be performed after a subscription.
     */
    protected function afterSubscribe(Channel $channel, Connection $connection): void
    {
        $this->sendInternally($connection, 'subscription_succeeded', $channel->data(), $channel->name());

        match (true) {
            $channel instanceof CacheChannel => $this->sendCachedPayload($channel, $connection),
            default => null,
        };
    }

    /**
     * Unsubscribe from the given channel.
     */
    public function unsubscribe(Connection $connection, string $channel): void
    {
        $this->channels
            ->for($connection->app())
            ->find($channel)
            ?->unsubscribe($connection);
    }

    /**
     * Send the cached payload for the given channel.
     */
    protected function sendCachedPayload(CacheChannel $channel, Connection $connection): void
    {
        if ($channel->hasCachedPayload()) {
            $connection->send(
                json_encode($channel->cachedPayload(), JSON_THROW_ON_ERROR)
            );

            return;
        }

        $this->send($connection, 'cache_miss', channel: $channel->name());

        $app = $connection->app();

        if ($app->hasWebhooks() && app(SharedState::class)->tryCacheMissLock($app->id(), $channel->name())) {
            app(WebhookDispatcher::class)->dispatch($app, 'cache_miss', [
                'channel' => $channel->name(),
            ]);
        }
    }

    /**
     * Respond to a ping on the given connection.
     */
    public function pong(Connection $connection): void
    {
        $this->send($connection, 'pong');
    }

    /**
     * Send a ping to the given connection.
     */
    public function ping(Connection $connection): void
    {
        $connection->usesControlFrames()
            ? $connection->control()
            : $this->send($connection, 'ping');

        $connection->ping();
    }

    /**
     * Send a response to the given connection.
     */
    public function send(Connection $connection, string $event, array $data = [], ?string $channel = null): void
    {
        $connection->send(
            $this->formatPayload($event, $data, $channel)
        );
    }

    /**
     * Send an internal response to the given connection.
     */
    public function sendInternally(Connection $connection, string $event, array $data = [], ?string $channel = null): void
    {
        $connection->send(
            $this->formatInternalPayload($event, $data, $channel)
        );
    }

    /**
     * Format the payload for the given event.
     */
    public function formatPayload(string $event, array $data = [], ?string $channel = null, string $prefix = 'pusher:'): string
    {
        return json_encode(
            array_filter([
                'event' => $prefix . $event,
                'data' => empty($data) ? null : json_encode($data, JSON_THROW_ON_ERROR),
                'channel' => $channel,
            ]),
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Format the internal payload for the given event.
     */
    public function formatInternalPayload(string $event, array $data = [], ?string $channel = null): string
    {
        return json_encode(
            array_filter([
                'event' => 'pusher_internal:' . $event,
                'data' => json_encode((object) $data, JSON_THROW_ON_ERROR),
                'channel' => $channel,
            ]),
            JSON_THROW_ON_ERROR
        );
    }
}
