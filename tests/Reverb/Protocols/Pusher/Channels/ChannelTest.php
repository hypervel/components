<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class ChannelTest extends ReverbTestCase
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
        $channel = new Channel('test-channel');

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, []);

        $channel->subscribe($this->connection);
    }

    public function testCanUnsubscribeAConnectionFromAChannel()
    {
        $channel = new Channel('test-channel');

        $this->channelConnectionManager->shouldReceive('remove')
            ->once()
            ->with($this->connection);

        // Subscribe first so SharedState has a count to decrement
        $channel->subscribe($this->connection);
        $channel->unsubscribe($this->connection);
    }

    public function testRemovesAChannelWhenNoSubscribersRemain()
    {
        $scopedManager = m::spy(ScopedChannelManager::class);
        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);
        $this->app->singleton(ChannelManager::class, fn () => $channelManager);

        $channel = new Channel('test-channel');

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, []);
        $this->channelConnectionManager->shouldReceive('remove')
            ->once()
            ->with($this->connection);

        $channel->subscribe($this->connection);
        $channel->unsubscribe($this->connection);

        $scopedManager->shouldHaveReceived('remove')
            ->once()
            ->with($channel);
    }

    public function testCanBroadcastToAllConnectionsOfAChannel()
    {
        $channel = new Channel('test-channel');

        $this->channelConnectionManager->shouldReceive('add');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory(3));

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testDoesNotBroadcastToTheConnectionSendingTheMessage()
    {
        $channel = new Channel('test-channel');

        $this->channelConnectionManager->shouldReceive('add');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory(3));

        $channel->broadcast(['foo' => 'bar'], collect($connections)->first()->connection());

        collect($connections)->first()->assertNothingReceived();
        collect(array_slice($connections, -2))->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }
}
