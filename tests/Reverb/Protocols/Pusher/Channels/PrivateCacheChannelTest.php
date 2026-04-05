<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\PrivateCacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class PrivateCacheChannelTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ChannelConnectionManager $channelConnectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection();
        $this->channelConnectionManager = m::spy(ChannelConnectionManager::class);
        $this->channelConnectionManager->shouldReceive('for')
            ->andReturn($this->channelConnectionManager);
        $this->app->bind(ChannelConnectionManager::class, fn () => $this->channelConnectionManager);
    }

    public function testCanSubscribeAConnectionToAChannel()
    {
        $channel = new PrivateCacheChannel('private-cache-test-channel');

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, []);

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-cache-test-channel'));
    }

    public function testCanUnsubscribeAConnectionFromAChannel()
    {
        $channel = new PrivateCacheChannel('private-cache-test-channel');

        $this->channelConnectionManager->shouldReceive('remove')
            ->once()
            ->with($this->connection);

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-cache-test-channel'));
        $channel->unsubscribe($this->connection);
    }

    public function testCanBroadcastToAllConnectionsOfAChannel()
    {
        $channel = new PrivateCacheChannel('test-channel');

        $this->channelConnectionManager->shouldReceive('add');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory(3));

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testFailsToSubscribeIfTheSignatureIsInvalid()
    {
        $channel = new PrivateCacheChannel('presence-test-channel');

        $this->channelConnectionManager->shouldNotReceive('subscribe');

        $this->expectException(ConnectionUnauthorized::class);

        $channel->subscribe($this->connection, 'invalid-signature');
    }

    public function testReceivesNoDataWhenNoPreviousEventTriggered()
    {
        $channel = new PrivateCacheChannel('private-cache-test-channel');

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, []);

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-cache-test-channel'));

        $this->connection->assertNothingReceived();
    }

    public function testStoresLastTriggeredEvent()
    {
        $channel = new PrivateCacheChannel('presence-test-channel');

        $this->assertFalse($channel->hasCachedPayload());

        $channel->broadcast(['foo' => 'bar']);

        $this->assertTrue($channel->hasCachedPayload());
        $this->assertEquals(['foo' => 'bar'], $channel->cachedPayload());
    }
}
