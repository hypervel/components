<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\PresenceChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;

class PrivateChannelTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ChannelConnectionManager $channelConnectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection;
        $this->channelConnectionManager = new ArrayChannelConnectionManager;
        $this->app->bind(ChannelConnectionManager::class, fn () => $this->channelConnectionManager);
    }

    public function testCanSubscribeAConnectionToAChannel(): void
    {
        $channel = new PrivateChannel('private-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-test-channel'));

        $this->assertTrue($channel->subscribed($this->connection));
    }

    public function testCanUnsubscribeAConnectionFromAChannel(): void
    {
        $channel = new PrivateChannel('private-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-test-channel'));
        $channel->unsubscribe($this->connection);

        $this->assertFalse($channel->subscribed($this->connection));
    }

    public function testCanBroadcastToAllConnectionsOfAChannel(): void
    {
        $channel = new PrivateChannel('test-channel');
        $connections = static::factory(3);

        foreach ($connections as $connection) {
            $this->channelConnectionManager->add($connection->connection(), []);
        }

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testFailsToSubscribeIfTheSignatureIsInvalid(): void
    {
        $channel = new PrivateChannel('private-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        try {
            $channel->subscribe($this->connection, 'invalid-signature');
        } finally {
            $this->assertFalse($channel->subscribed($this->connection));
        }
    }

    public function testFailsToSubscribeToAPrivateChannelWithNoAuthToken(): void
    {
        $channel = new PrivateChannel('private-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        $channel->subscribe($this->connection, null);
    }

    public function testFailsToSubscribeToAPresenceChannelWithNoAuthToken(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        $channel->subscribe($this->connection, null);
    }
}
