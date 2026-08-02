<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Jobs;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Throwable;

class PingInactiveConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pinging Inactive Connections');

        $pusher = new EventHandler($channels);
        $exception = null;

        foreach (WebSocketHandler::connections() as $lifecycle) {
            try {
                $lifecycle->run(function (ConnectionLifecycle $lifecycle) use ($pusher): void {
                    $connection = $lifecycle->connection();

                    if ($connection === null || ! $connection->isEstablished() || $connection->isActive()) {
                        return;
                    }

                    $pusher->ping($connection);

                    Log::info('Connection Pinged', $connection->id());
                });
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}
