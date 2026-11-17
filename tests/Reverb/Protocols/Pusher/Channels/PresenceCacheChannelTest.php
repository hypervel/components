<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\Channels\PresenceCacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;

class PresenceCacheChannelTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ChannelConnectionManager $channelConnectionManager;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection;
        $this->channelConnectionManager = Double::for(new ArrayChannelConnectionManager)->passthru();
        $this->app->bind(ChannelConnectionManager::class, fn () => $this->channelConnectionManager);
    }

    public function testCanSubscribeAConnectionToAChannel(): void
    {
        $channel = new PresenceCacheChannel('presence-cache-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-cache-test-channel'));

        $this->assertTrue($channel->subscribed($this->connection));
    }

    public function testCanUnsubscribeAConnectionFromAChannel(): void
    {
        $channel = new PresenceCacheChannel('presence-cache-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-cache-test-channel'));
        $channel->unsubscribe($this->connection);

        $this->assertFalse($channel->subscribed($this->connection));
    }

    public function testCanBroadcastToAllConnectionsOfAChannel(): void
    {
        $channel = new PresenceCacheChannel('presence-cache-test-channel');

        $this->channelConnectionManager->expects('all')
            ->returns($connections = static::factory(3));

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testFailsToSubscribeIfTheSignatureIsInvalid(): void
    {
        $channel = new PresenceCacheChannel('presence-cache-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        try {
            $channel->subscribe($this->connection, 'invalid-signature');
        } finally {
            $this->assertFalse($channel->subscribed($this->connection));
        }
    }

    public function testCanReturnDataStoredOnTheConnection(): void
    {
        $channel = $this->channels()->findOrCreate('presence-cache-test-channel');

        $connections = [
            collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first(),
            collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 2]))->first(),
        ];

        $this->channelConnectionManager->expects('all')
            ->times(2)
            ->returns($connections);

        $this->assertSame([
            'presence' => [
                'count' => 2,
                'ids' => [1, 2],
                'hash' => [
                    1 => ['name' => 'Joe'],
                    2 => ['name' => 'Joe'],
                ],
            ],
        ], $channel->data());
    }

    public function testSendsNotificationOfSubscription(): void
    {
        $channel = $this->channels()->findOrCreate('presence-cache-test-channel');

        $this->channelConnectionManager->expects('add')
            ->with($this->connection, []);

        $this->channelConnectionManager->allows('all')
            ->returns($connections = static::factory(3));

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-cache-test-channel'));

        collect($connections)->each(fn ($connection) => $connection->assertReceived([
            'event' => 'pusher_internal:member_added',
            'data' => '{}',
            'channel' => 'presence-cache-test-channel',
        ]));
    }

    public function testSendsNotificationOfSubscriptionWithData(): void
    {
        $channel = $this->channels()->findOrCreate('presence-cache-test-channel');
        $data = json_encode(['name' => 'Joe']);

        $this->channelConnectionManager->expects('add')
            ->with($this->connection, ['name' => 'Joe']);

        $this->channelConnectionManager->allows('all')
            ->returns($connections = static::factory(3));

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-cache-test-channel', $data),
            $data
        );

        collect($connections)->each(fn ($connection) => $connection->assertReceived([
            'event' => 'pusher_internal:member_added',
            'data' => json_encode(['name' => 'Joe']),
            'channel' => 'presence-cache-test-channel',
        ]));
    }

    public function testSendsNotificationOfAnUnsubscribe(): void
    {
        $channel = $this->channels()->findOrCreate('presence-cache-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Joe'], 'user_id' => 1]);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-cache-test-channel', $data),
            $data
        );

        $this->channelConnectionManager->allows('find')
            ->returns(new ChannelConnection($this->connection, ['user_info' => ['name' => 'Joe'], 'user_id' => 1]));

        $this->channelConnectionManager->allows('all')
            ->returns($connections = static::factory(3));

        $this->channelConnectionManager->expects('remove')
            ->with($this->connection);

        $channel->unsubscribe($this->connection);

        collect($connections)->each(fn ($connection) => $connection->assertReceived([
            'event' => 'pusher_internal:member_removed',
            'data' => json_encode(['user_id' => '1']),
            'channel' => 'presence-cache-test-channel',
        ]));
    }

    public function testReceivesNoDataWhenNoPreviousEventTriggered(): void
    {
        $channel = $this->channels()->findOrCreate('presence-cache-test-channel');

        $this->channelConnectionManager->expects('add')
            ->with($this->connection, []);

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-cache-test-channel'));

        $this->connection->assertNothingReceived();
    }

    public function testStoresLastTriggeredEvent(): void
    {
        $channel = new PresenceCacheChannel('presence-cache-test-channel');

        $this->assertFalse($channel->hasCachedPayload());

        $channel->broadcast(['foo' => 'bar']);

        $this->assertTrue($channel->hasCachedPayload());
        $this->assertEquals(['foo' => 'bar'], $channel->cachedPayload());
    }

    public function testDoesNotCacheInternalEventsOnAPresenceCacheChannel(): void
    {
        $channel = $this->channels()->findOrCreate('presence-cache-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Joe'], 'user_id' => 1]);

        $this->channelConnectionManager->allows('all')->returns([]);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-cache-test-channel', $data),
            $data
        );

        $this->assertFalse($channel->hasCachedPayload());

        // Hypervel removes a vacated channel, so dispatch the remote member
        // event while the channel is still registered.
        EventDispatcher::dispatchInternallySynchronously($this->connection->app(), [
            'event' => 'pusher_internal:member_added',
            'data' => json_encode(['user_id' => 2]),
            'channel' => 'presence-cache-test-channel',
        ]);

        $this->assertFalse($channel->hasCachedPayload());

        $this->channelConnectionManager->allows('find')
            ->returns(new ChannelConnection($this->connection, ['user_info' => ['name' => 'Joe'], 'user_id' => 1]));

        $channel->unsubscribe($this->connection);

        $this->assertFalse($channel->hasCachedPayload());
    }
}
