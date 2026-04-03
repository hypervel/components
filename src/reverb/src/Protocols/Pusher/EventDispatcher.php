<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
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
    public static function dispatchSynchronously(Application $app, array $payload, ?Connection $connection = null): void
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
        }

        // Fan out to other workers — pass the base payload without a channel
        // name baked in. The receiver sets the correct channel per iteration.
        unset($payload['channel']);

        static::fanOutToOtherWorkers($app, $channels, $payload, $connection);
    }

    /**
     * Send a broadcast pipe message to all other workers on this node.
     */
    protected static function fanOutToOtherWorkers(Application $app, array $channels, array $payload, ?Connection $connection): void
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
        );

        for ($workerId = 0; $workerId < $workerNum; ++$workerId) {
            if ($workerId !== $currentWorkerId) {
                $server->sendMessage($message, $workerId);
            }
        }
    }
}
