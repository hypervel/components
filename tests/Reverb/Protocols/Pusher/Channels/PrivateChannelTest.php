<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\PresenceChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class PrivateChannelTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ChannelConnectionManager $channelConnectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection;
        $this->channelConnectionManager = m::spy(ChannelConnectionManager::class);
        $this->channelConnectionManager->shouldReceive('for')
            ->andReturn($this->channelConnectionManager);
        $this->app->bind(ChannelConnectionManager::class, fn () => $this->channelConnectionManager);
    }

    public function testCanSubscribeAConnectionToAChannel()
    {
        $channel = new PrivateChannel('private-test-channel');

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, []);

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-test-channel'));
    }

    public function testCanUnsubscribeAConnectionFromAChannel()
    {
        $channel = new PrivateChannel('private-test-channel');

        $this->channelConnectionManager->shouldReceive('remove')
            ->once()
            ->with($this->connection);

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-test-channel'));
        $channel->unsubscribe($this->connection);
    }

    public function testCanBroadcastToAllConnectionsOfAChannel()
    {
        $channel = new PrivateChannel('test-channel');

        $this->channelConnectionManager->shouldReceive('add');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory(3));

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testFailsToSubscribeIfTheSignatureIsInvalid()
    {
        $channel = new PrivateChannel('private-test-channel');

        $this->channelConnectionManager->shouldNotReceive('subscribe');

        $this->expectException(ConnectionUnauthorized::class);

        $channel->subscribe($this->connection, 'invalid-signature');
    }

    public function testFailsToSubscribeToAPrivateChannelWithNoAuthToken()
    {
        $channel = new PrivateChannel('private-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        $channel->subscribe($this->connection, null);
    }

    public function testFailsToSubscribeToAPresenceChannelWithNoAuthToken()
    {
        $channel = new PresenceChannel('presence-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        $channel->subscribe($this->connection, null);
    }
}
