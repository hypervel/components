<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Jobs;

use Hypervel\Reverb\Jobs\PruneStaleConnections;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use ReflectionProperty;

class PruneStaleConnectionsTest extends ReverbTestCase
{
    public function testCleansUpStaleConnectionsIncludingConnectionsWithoutSubscriptions(): void
    {
        $connections = static::factory(5);

        collect($connections)->each(function ($connection, int $index): void {
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->setHasBeenPinged();
            $connection->connection()->markEstablished();
            $this->addToWebSocketHandler($index + 1, $connection->connection());
        });

        (new PruneStaleConnections)->handle();

        // Verify all stale connections were disconnected
        collect($connections)->each(function ($connection): void {
            $connection->connection()->assertHasBeenTerminated();
        });
    }

    public function testDoesNotCallUnsubscribeFromAllDirectly(): void
    {
        $connections = static::factory(1);

        $channelManager = m::spy(ChannelManager::class);
        $this->app->singleton(ChannelManager::class, fn () => $channelManager);

        collect($connections)->each(function ($connection): void {
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->setHasBeenPinged();
            $connection->connection()->markEstablished();
            $this->addToWebSocketHandler(1, $connection->connection());
        });

        (new PruneStaleConnections)->handle();

        // PruneStaleConnections should NOT call unsubscribeFromAll directly.
        // It should only disconnect the connection, and the onClose → Server::close()
        // path handles the unsubscribe. Calling it directly causes double-unsubscribe
        // which decrements SharedState counters twice.
        $channelManager->shouldNotHaveReceived('for');
    }

    /**
     * Add a connection to the WebSocket handler registry.
     */
    private function addToWebSocketHandler(int $fd, \Hypervel\Reverb\Contracts\Connection $connection): void
    {
        $lifecycle = new ConnectionLifecycle($fd);
        $lifecycle->attach($connection);

        $property = new ReflectionProperty(WebSocketHandler::class, 'connections');
        $connections = $property->getValue();
        $connections[$fd] = $lifecycle;
        $property->setValue(null, $connections);
    }
}
