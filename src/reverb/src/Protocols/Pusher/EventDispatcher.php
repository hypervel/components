<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Support\Arr;
use Swoole\Server;

class EventDispatcher
{
    /**
     * Dispatch a message to a channel.
     */
    public static function dispatch(Application $app, array $payload, ?Connection $connection = null): void
    {
        $server = app(ServerProviderManager::class);

        if ($server->shouldNotPublishEvents()) {
            static::dispatchSynchronously($app, $payload, $connection);

            return;
        }

        $data = [
            'type' => 'message',
            'app_id' => $app->id(),
            'payload' => $payload,
        ];

        if ($connection?->id() !== null) {
            $data['socket_id'] = $connection->id();
        }

        app(PubSubProvider::class)->publish($data);
    }

    /**
     * Notify all connections subscribed to the given channels.
     *
     * In single-node mode, also sends a pipe message to all other workers
     * so they can broadcast to their local connections.
     */
    public static function dispatchSynchronously(Application $app, array $payload, ?Connection $connection = null, bool $fanOut = true): void
    {
        $channels = Arr::wrap($payload['channels'] ?? $payload['channel'] ?? []);

        // Remove multi-channel key before broadcasting — each channel gets
        // its own payload with the correct 'channel' name set per iteration.
        unset($payload['channels']);

        foreach ($channels as $channel) {
            if (! $channel = app(ChannelManager::class)->for($app)->find($channel)) {
                continue;
            }

            $payload['channel'] = $channel->name();

            $channel->broadcast($payload, $connection);

            if ($channel instanceof CacheChannel) {
                app(SharedState::class)->clearCacheMissLock($app->id(), $channel->name());
            }
        }

        if (! $fanOut) {
            return;
        }

        // Fan out to other workers — pass the base payload without a channel
        // name baked in. The receiver sets the correct channel per iteration.
        unset($payload['channel']);

        static::fanOutToOtherWorkers($app, $channels, $payload, $connection);
    }

    /**
     * Notify all connections using broadcastInternally (no cache mutation).
     *
     * Used for internal protocol events (member_added, member_removed)
     * that should not populate cache channel payloads.
     */
    public static function dispatchInternallySynchronously(Application $app, array $payload, ?Connection $connection = null, bool $fanOut = true): void
    {
        $channels = Arr::wrap($payload['channels'] ?? $payload['channel'] ?? []);

        unset($payload['channels']);

        foreach ($channels as $channel) {
            if (! $channel = app(ChannelManager::class)->for($app)->find($channel)) {
                continue;
            }

            $payload['channel'] = $channel->name();

            $channel->broadcastInternally($payload, $connection);
        }

        if (! $fanOut) {
            return;
        }

        unset($payload['channel']);

        static::fanOutToOtherWorkers($app, $channels, $payload, $connection, internal: true);
    }

    /**
     * Dispatch an internal protocol message to a known channel instance.
     *
     * Uses broadcastInternally() instead of broadcast() so cache channels
     * don't store the payload (e.g. member_added/member_removed should not
     * overwrite the cached event payload).
     *
     * Takes the channel directly instead of resolving from the ChannelManager,
     * since the channel may have already been removed (e.g. after vacate).
     *
     * In scaling mode, publishes to Redis with an 'internal' flag so the
     * receiving handler uses broadcastInternally() on the remote node too.
     */
    public static function dispatchInternalToChannel(Application $app, Channel $channel, array $payload, ?Connection $connection = null): void
    {
        $server = app(ServerProviderManager::class);

        if ($server->shouldNotPublishEvents()) {
            $payload['channel'] = $channel->name();
            $channel->broadcastInternally($payload, $connection);

            unset($payload['channel']);
            static::fanOutToOtherWorkers($app, [$channel->name()], $payload, $connection, internal: true);

            return;
        }

        $data = [
            'type' => 'message',
            'internal' => true,
            'app_id' => $app->id(),
            'payload' => $payload,
        ];

        if ($connection?->id() !== null) {
            $data['socket_id'] = $connection->id();
        }

        app(PubSubProvider::class)->publish($data);
    }

    /**
     * Send a broadcast pipe message to all other workers on this node.
     */
    protected static function fanOutToOtherWorkers(Application $app, array $channels, array $payload, ?Connection $connection, bool $internal = false): void
    {
        $server = app(Server::class);
        $workerNum = $server->setting['worker_num'] ?? 1;
        $currentWorkerId = $server->worker_id;

        if ($workerNum <= 1) {
            return;
        }

        $message = new ChannelBroadcastPipeMessage(
            appId: $app->id(),
            channels: $channels,
            payload: $payload,
            exceptSocketId: $connection?->id(),
            internal: $internal,
        );

        for ($workerId = 0; $workerId < $workerNum; ++$workerId) {
            if ($workerId !== $currentWorkerId) {
                $server->sendMessage($message, $workerId);
            }
        }
    }
}
