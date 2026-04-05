<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Jobs;

use Hypervel\Reverb\Jobs\PingInactiveConnections;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelBroker;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class PingInactiveConnectionsTest extends ReverbTestCase
{
    public function testPingsInactiveConnections()
    {
        $connections = static::factory(5);
        $channel = ChannelBroker::create('test-channel');

        $scopedManager = m::spy(ScopedChannelManager::class);
        $scopedManager->shouldReceive('connections')
            ->once()
            ->andReturn($connections);

        $channelManager = m::spy(ChannelManager::class);
        $channelManager->shouldReceive('for')
            ->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);

        $connections = collect($connections)->each(function ($connection) use ($channel) {
            $channel->subscribe($connection->connection());
            $connection->setLastSeenAt(time() - 60 * 10);
        });

        (new PingInactiveConnections())->handle($channelManager);

        $connections->each(function ($connection) {
            $connection->assertReceived([
                'event' => 'pusher:ping',
            ]);
            $connection->assertHasBeenPinged();
        });
    }
}
