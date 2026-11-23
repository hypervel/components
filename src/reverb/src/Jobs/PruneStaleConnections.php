<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Jobs;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Events\ConnectionPruned;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ScopedChannelManager;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Throwable;

class PruneStaleConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pruning Stale Connections');
        $exception = null;

        foreach (WebSocketHandler::connections() as $lifecycle) {
            try {
                $lifecycle->run(function (ConnectionLifecycle $lifecycle) use ($channels): void {
                    $connection = $lifecycle->connection();

                    if ($connection === null || ! $connection->isEstablished() || ! $connection->isStale()) {
                        return;
                    }

                    $connectionFailure = null;

                    try {
                        $connection->send(json_encode([
                            'event' => 'pusher:error',
                            'data' => json_encode([
                                'code' => 4201,
                                'message' => 'Pong reply not received in time',
                            ]),
                        ]));
                    } catch (Throwable $throwable) {
                        $connectionFailure = $throwable;
                    }

                    try {
                        // The onClose callback owns protocol and shared-state cleanup.
                        $connection->disconnect();
                    } catch (Throwable $throwable) {
                        $connectionFailure = Coroutine::preferCancellation($connectionFailure, $throwable);
                    }

                    try {
                        Log::info('Connection Pruned', $connection->id());

                        if (app('events')->hasListeners(ConnectionPruned::class)) {
                            // onClose waits for this lifecycle, so the subscriptions are still available here.
                            ConnectionPruned::dispatch($this->channelConnection($channels->for($connection->app()), $connection));
                        }
                    } catch (Throwable $throwable) {
                        $connectionFailure = Coroutine::preferCancellation($connectionFailure, $throwable);
                    }

                    if ($connectionFailure !== null) {
                        throw $connectionFailure;
                    }
                });
            } catch (Throwable $throwable) {
                $exception = Coroutine::preferCancellation($exception, $throwable);
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Get the connection's channel subscription, preferring one with user data.
     */
    protected function channelConnection(ScopedChannelManager $channels, Connection $connection): ChannelConnection
    {
        $fallback = null;

        foreach ($channels->all() as $channel) {
            $channelConnection = $channel->findById($connection->id());

            if ($channelConnection?->data('user_id') !== null) {
                return $channelConnection;
            }

            $fallback ??= $channelConnection;
        }

        return $fallback ?? new ChannelConnection($connection);
    }
}
