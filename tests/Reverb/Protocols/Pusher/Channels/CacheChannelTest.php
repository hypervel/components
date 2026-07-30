<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelBroker;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;

class CacheChannelTest extends ReverbTestCase
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

    public function testReceivesNoDataWhenNoPreviousEventTriggered(): void
    {
        $channel = ChannelBroker::create('cache-test-channel');

        $channel->subscribe($this->connection);

        $this->assertTrue($channel->subscribed($this->connection));
        $this->connection->assertNothingReceived();
    }

    public function testStoresLastTriggeredEvent(): void
    {
        $channel = new CacheChannel('cache-test-channel');

        $this->assertFalse($channel->hasCachedPayload());

        $channel->broadcast(['foo' => 'bar']);

        $this->assertTrue($channel->hasCachedPayload());
        $this->assertEquals(['foo' => 'bar'], $channel->cachedPayload());
    }
}
