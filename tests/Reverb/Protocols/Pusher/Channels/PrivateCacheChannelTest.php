<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\PrivateCacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;

class PrivateCacheChannelTest extends ReverbTestCase
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
        $channel = new PrivateCacheChannel('private-cache-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-cache-test-channel'));

        $this->assertTrue($channel->subscribed($this->connection));
    }

    public function testCanUnsubscribeAConnectionFromAChannel(): void
    {
        $channel = new PrivateCacheChannel('private-cache-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-cache-test-channel'));
        $channel->unsubscribe($this->connection);

        $this->assertFalse($channel->subscribed($this->connection));
    }

    public function testCanBroadcastToAllConnectionsOfAChannel(): void
    {
        $channel = new PrivateCacheChannel('test-channel');

        $connections = static::factory(3);

        foreach ($connections as $connection) {
            $this->channelConnectionManager->add($connection->connection(), []);
        }

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testFailsToSubscribeIfTheSignatureIsInvalid(): void
    {
        $channel = new PrivateCacheChannel('presence-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        try {
            $channel->subscribe($this->connection, 'invalid-signature');
        } finally {
            $this->assertFalse($channel->subscribed($this->connection));
        }
    }

    public function testReceivesNoDataWhenNoPreviousEventTriggered(): void
    {
        $channel = new PrivateCacheChannel('private-cache-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'private-cache-test-channel'));

        $this->connection->assertNothingReceived();
    }

    public function testStoresLastTriggeredEvent(): void
    {
        $channel = new PrivateCacheChannel('presence-test-channel');

        $this->assertFalse($channel->hasCachedPayload());

        $channel->broadcast(['foo' => 'bar']);

        $this->assertTrue($channel->hasCachedPayload());
        $this->assertEquals(['foo' => 'bar'], $channel->cachedPayload());
    }
}
