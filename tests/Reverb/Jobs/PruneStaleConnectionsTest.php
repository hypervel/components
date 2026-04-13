<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Jobs;

use Hypervel\Reverb\Jobs\PruneStaleConnections;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelBroker;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class PruneStaleConnectionsTest extends ReverbTestCase
{
    public function testCleansUpStaleConnections()
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

        collect($connections)->each(function ($connection) use ($channel) {
            $channel->subscribe($connection->connection());
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->setHasBeenPinged();
        });

        (new PruneStaleConnections)->handle($channelManager);

        // Verify all stale connections were disconnected
        collect($connections)->each(function ($connection) {
            $connection->connection()->assertHasBeenTerminated();
        });
    }

    public function testDoesNotCallUnsubscribeFromAllDirectly()
    {
        $connections = static::factory(1);
        $channel = ChannelBroker::create('test-channel');

        $scopedManager = m::spy(ScopedChannelManager::class);
        $scopedManager->shouldReceive('connections')
            ->andReturn($connections);

        $channelManager = m::spy(ChannelManager::class);
        $channelManager->shouldReceive('for')
            ->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);

        collect($connections)->each(function ($connection) use ($channel) {
            $channel->subscribe($connection->connection());
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->setHasBeenPinged();
        });

        (new PruneStaleConnections)->handle($channelManager);

        // PruneStaleConnections should NOT call unsubscribeFromAll directly.
        // It should only disconnect the connection, and the onClose → Server::close()
        // path handles the unsubscribe. Calling it directly causes double-unsubscribe
        // which decrements SharedState counters twice.
        $scopedManager->shouldNotHaveReceived('unsubscribeFromAll');
    }
}
