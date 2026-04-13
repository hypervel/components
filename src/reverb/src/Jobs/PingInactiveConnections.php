<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Jobs;

use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;

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

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels, $pusher) {
                foreach ($channels->for($application)->connections() as $connection) {
                    if ($connection->isActive()) {
                        continue;
                    }

                    $pusher->ping($connection->connection());

                    Log::info('Connection Pinged', $connection->id());
                }
            });
    }
}
