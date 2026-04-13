<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Jobs;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Events\ConnectionPruned;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;

class PruneStaleConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pruning Stale Connections');

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels) {
                foreach ($channels->for($application)->connections() as $connection) {
                    if (! $connection->isStale()) {
                        continue;
                    }

                    $connection->send(json_encode([
                        'event' => 'pusher:error',
                        'data' => json_encode([
                            'code' => 4201,
                            'message' => 'Pong reply not received in time',
                        ]),
                    ]));

                    // Only disconnect — the onClose → Server::close() path handles
                    // unsubscribeFromAll and slot release. Calling unsubscribeFromAll
                    // here would cause double-unsubscribe, decrementing SharedState
                    // counters twice.
                    $connection->disconnect();

                    Log::info('Connection Pruned', $connection->id());

                    if (app('events')->hasListeners(ConnectionPruned::class)) {
                        ConnectionPruned::dispatch($connection);
                    }
                }
            });
    }
}
