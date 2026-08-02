<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Jobs;

use Hypervel\Reverb\Jobs\PingInactiveConnections;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Servers\Hypervel\ConnectionLifecycle;
use Hypervel\Reverb\Servers\Hypervel\WebSocketHandler;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use ReflectionProperty;
use RuntimeException;

class PingInactiveConnectionsTest extends ReverbTestCase
{
    public function testPingsInactiveConnectionsIncludingConnectionsWithoutSubscriptions(): void
    {
        $connections = static::factory(5);
        $connections = collect($connections)->each(function ($connection, int $index): void {
            $connection->setLastSeenAt(time() - 60 * 10);
            $connection->connection()->markEstablished();
            $this->addToWebSocketHandler($index + 1, $connection->connection());
        });

        (new PingInactiveConnections)->handle($this->app->make(ChannelManager::class));

        $connections->each(function ($connection): void {
            $connection->assertReceived([
                'event' => 'pusher:ping',
            ]);
            $connection->assertHasBeenPinged();
        });
    }

    public function testPingFailureDoesNotAbandonLaterConnections(): void
    {
        $failure = new RuntimeException('Unable to send ping.');
        $failing = new class($failure) extends FakeConnection {
            public function __construct(private readonly RuntimeException $failure)
            {
                parent::__construct('failing');
            }

            public function send(string $message): void
            {
                throw $this->failure;
            }
        };
        $healthy = new FakeConnection('healthy');

        foreach ([$failing, $healthy] as $index => $connection) {
            $connection->setLastSeenAt(time() - 600);
            $connection->markEstablished();
            $this->addToWebSocketHandler($index + 1, $connection);
        }

        try {
            (new PingInactiveConnections)->handle($this->app->make(ChannelManager::class));
            $this->fail('Expected the first ping failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $healthy->assertReceived(['event' => 'pusher:ping']);
        $healthy->assertHasBeenPinged();
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
