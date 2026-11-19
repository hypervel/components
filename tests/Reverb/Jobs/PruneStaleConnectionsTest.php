<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Jobs;

use Hypervel\Reverb\Events\ConnectionPruned;
use Hypervel\Reverb\Jobs\PruneStaleConnections;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
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

        (new PruneStaleConnections)->handle($this->app->make(ChannelManager::class));

        // Verify all stale connections were disconnected
        collect($connections)->each(function ($connection): void {
            $connection->connection()->assertHasBeenTerminated();
        });
    }

    public function testDoesNotCallUnsubscribeFromAllDirectly(): void
    {
        $connections = static::factory(1);

        $channelManager = Double::for(ChannelManager::class)->strict();
        $this->app->singleton(ChannelManager::class, fn () => $channelManager);

        collect($connections)->each(function ($connection): void {
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->setHasBeenPinged();
            $connection->connection()->markEstablished();
            $this->addToWebSocketHandler(1, $connection->connection());
        });

        (new PruneStaleConnections)->handle($channelManager);

        // PruneStaleConnections should NOT call unsubscribeFromAll directly.
        // It should only disconnect the connection, and the onClose → Server::close()
        // path handles the unsubscribe. Calling it directly causes double-unsubscribe
        // which decrements SharedState counters twice.
        $channelManager->received('for')->never();
    }

    public function testDispatchesConnectionPrunedWithTheStaleConnection(): void
    {
        $connections = [];

        foreach (range(1, 5) as $fd) {
            // The presence subscription comes second, so the event must prefer it over the first match.
            $connection = $this->subscribeConnection('test-channel');
            $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']], $connection);
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->setHasBeenPinged();
            $connection->markEstablished();
            $this->addToWebSocketHandler($fd, $connection);
            $connections[] = $connection;
        }

        $events = [];
        $this->app->make('events')->listen(ConnectionPruned::class, function (ConnectionPruned $event) use (&$events): void {
            $events[] = $event;
        });

        (new PruneStaleConnections)->handle($this->app->make(ChannelManager::class));

        $this->assertCount(5, $events);

        foreach ($events as $index => $event) {
            $this->assertSame($connections[$index], $event->connection->connection());
            $this->assertSame(1, $event->connection->data('user_id'));
        }

        // The onClose path owns unsubscribing, even when listeners need the subscriptions.
        $this->assertCount(5, $this->channels()->find('presence-test-channel')->connections());
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
