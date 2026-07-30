<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Jobs;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Reverb\Events\ConnectionPruned;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Throwable;

class PruneStaleConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Pruning Stale Connections');
        $exception = null;

        foreach (WebSocketHandler::connections() as $lifecycle) {
            try {
                $lifecycle->run(function (ConnectionLifecycle $lifecycle): void {
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
                        $connectionFailure ??= $throwable;
                    }

                    try {
                        Log::info('Connection Pruned', $connection->id());

                        if (app('events')->hasListeners(ConnectionPruned::class)) {
                            ConnectionPruned::dispatch($connection);
                        }
                    } catch (Throwable $throwable) {
                        $connectionFailure ??= $throwable;
                    }

                    if ($connectionFailure !== null) {
                        throw $connectionFailure;
                    }
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
