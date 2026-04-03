<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class MetricsHandlerTest extends ReverbTestCase
{
    protected MetricsHandler $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->shouldReceive('subscribesToEvents')->andReturn(false);

        $pubSub = m::mock(PubSubProvider::class);

        $this->metrics = new MetricsHandler(
            $serverManager,
            $this->app->make(ChannelManager::class),
            $pubSub,
        );
    }

    public function testGatherConnectionsCount()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $result = $this->metrics->gather($app, 'connections');

        $this->assertCount(2, $result);
    }

    public function testGatherConnectionsDeduplicatesSameConnectionOnMultipleChannels()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $connection = $this->subscribeConnection('test-channel-one');

        // Subscribe same connection to a second channel
        $channel = $this->channels()->findOrCreate('test-channel-two');
        $channel->subscribe($connection);

        $result = $this->metrics->gather($app, 'connections');

        // Same connection on two channels should appear once (keyed by connection ID)
        $this->assertCount(1, $result);
    }

    public function testGatherConnectionsReturnsEmptyWhenNoConnections()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $result = $this->metrics->gather($app, 'connections');

        $this->assertSame([], $result);
    }

    public function testGatherChannelsReturnsOccupiedChannels()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $result = $this->metrics->gather($app, 'channels');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('test-channel-one', $result);
        $this->assertArrayHasKey('test-channel-two', $result);
    }

    public function testGatherChannelsWithSubscriptionCountInfo()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        $result = $this->metrics->gather($app, 'channels', ['info' => 'subscription_count']);

        $this->assertSame(2, $result['test-channel-one']['subscription_count']);
        $this->assertSame(1, $result['test-channel-two']['subscription_count']);
    }

    public function testGatherChannelsWithPrefixFilter()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $result = $this->metrics->gather($app, 'channels', ['filter' => 'presence-']);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('presence-test-channel', $result);
        $this->assertArrayNotHasKey('test-channel-one', $result);
    }

    public function testGatherChannelsExcludesEmptyChannels()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $connection = $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-two');

        // Unsubscribe from channel one so it's empty
        $this->channels()->find('test-channel-one')->unsubscribe($connection);

        $result = $this->metrics->gather($app, 'channels');

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('test-channel-one', $result);
        $this->assertArrayHasKey('test-channel-two', $result);
    }

    public function testGatherChannelsWithUserCountForPresence()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $this->subscribeConnection('test-channel');

        $result = $this->metrics->gather($app, 'channels', ['info' => 'user_count']);

        $this->assertSame(1, $result['presence-test-channel']['user_count']);
        // Non-presence channels don't have user_count
        $this->assertSame([], $result['test-channel']);
    }

    public function testGatherSingleChannelInfo()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('test-channel-one');
        $this->subscribeConnection('test-channel-one');

        $result = $this->metrics->gather($app, 'channel', [
            'channel' => 'test-channel-one',
            'info' => 'occupied,subscription_count',
        ]);

        $this->assertTrue($result['occupied']);
        $this->assertSame(2, $result['subscription_count']);
    }

    public function testGatherSingleChannelReturnsUnoccupiedWhenNoConnections()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $result = $this->metrics->gather($app, 'channel', [
            'channel' => 'nonexistent-channel',
            'info' => 'occupied,subscription_count',
        ]);

        $this->assertFalse($result['occupied']);
        $this->assertArrayNotHasKey('subscription_count', $result);
    }

    public function testGatherChannelUsersForPresenceChannel()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $channel = $this->channels()->findOrCreate('presence-test-channel');

        $connection = new FakeConnection('conn-one');
        $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $connection = new FakeConnection('conn-two');
        $data = json_encode(['user_id' => 2, 'user_info' => ['name' => 'Joe']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $result = $this->metrics->gather($app, 'channel_users', ['channel' => 'presence-test-channel']);

        $this->assertSame([['id' => 1], ['id' => 2]], $result);
    }

    public function testGatherChannelUsersDeduplicatesSameUser()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $channel = $this->channels()->findOrCreate('presence-test-channel');

        $connection = new FakeConnection('conn-one');
        $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $connection = new FakeConnection('conn-two');
        $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $channel->subscribe($connection, static::validAuth($connection->id(), 'presence-test-channel', $data), $data);

        $result = $this->metrics->gather($app, 'channel_users', ['channel' => 'presence-test-channel']);

        $this->assertSame([['id' => 1]], $result);
    }

    public function testGatherChannelUsersReturnsEmptyForNonexistentChannel()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $result = $this->metrics->gather($app, 'channel_users', ['channel' => 'nonexistent']);

        $this->assertSame([], $result);
    }

    public function testGatherSingleChannelCacheInfo()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $this->subscribeConnection('cache-test-channel');
        $this->channels()->find('cache-test-channel')->broadcast(['cached' => 'data']);

        $result = $this->metrics->gather($app, 'channel', [
            'channel' => 'cache-test-channel',
            'info' => 'occupied,subscription_count,cache',
        ]);

        $this->assertTrue($result['occupied']);
        $this->assertSame(1, $result['subscription_count']);
        $this->assertSame(['cached' => 'data'], $result['cache']);
    }

    // ── Scaling path tests (gatherMetricsFromSubscribers) ──────────────

    /**
     * Create a MetricsHandler wired for the scaling path.
     *
     * The mock PubSubProvider captures the on() callback, then when publish()
     * is called, it immediately invokes that callback with the given responses,
     * simulating subscribers that respond before the coroutine channel pop().
     */
    private function scalingMetricsHandler(array $subscriberResponses): MetricsHandler
    {
        $serverManager = m::mock(ServerProviderManager::class);
        $serverManager->shouldReceive('subscribesToEvents')->andReturn(true);

        $capturedCallback = null;

        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldReceive('on')->once()->andReturnUsing(function (string $event, callable $callback) use (&$capturedCallback) {
            $capturedCallback = $callback;
        });
        $pubSub->shouldReceive('publish')->once()->andReturnUsing(function () use (&$capturedCallback, $subscriberResponses) {
            // Simulate each subscriber responding immediately
            foreach ($subscriberResponses as $response) {
                $capturedCallback(['payload' => $response]);
            }

            return count($subscriberResponses);
        });
        $pubSub->shouldReceive('stopListening')->once();

        return new MetricsHandler(
            $serverManager,
            $this->app->make(ChannelManager::class),
            $pubSub,
        );
    }

    public function testScalingGatherConnectionsMergesFromSubscribers()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        // Simulate two subscribers each reporting connections
        $handler = $this->scalingMetricsHandler([
            ['conn-1' => 'data1', 'conn-2' => 'data2'],
            ['conn-3' => 'data3'],
        ]);

        $result = $handler->gather($app, 'connections');

        $this->assertCount(3, $result);
    }

    public function testScalingGatherChannelsMergesSubscriptionCounts()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        // Two subscribers each report the same channel with different subscription counts
        $handler = $this->scalingMetricsHandler([
            ['test-channel' => ['subscription_count' => 3, 'occupied' => true]],
            ['test-channel' => ['subscription_count' => 2, 'occupied' => true]],
        ]);

        $result = $handler->gather($app, 'channels', ['info' => 'subscription_count']);

        $this->assertSame(5, $result['test-channel']['subscription_count']);
        $this->assertTrue($result['test-channel']['occupied']);
    }

    public function testScalingGatherSingleChannelMergesFromSubscribers()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = $this->scalingMetricsHandler([
            ['occupied' => true, 'subscription_count' => 2],
            ['occupied' => false, 'subscription_count' => 1],
        ]);

        $result = $handler->gather($app, 'channel', [
            'channel' => 'test-channel',
            'info' => 'occupied,subscription_count',
        ]);

        // occupied should be OR'd (true || false = true)
        $this->assertTrue($result['occupied']);
        // subscription_count should be summed (2 + 1 = 3)
        $this->assertSame(3, $result['subscription_count']);
    }

    public function testScalingGatherChannelUsersDeduplicatesAcrossSubscribers()
    {
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = $this->scalingMetricsHandler([
            [['id' => 1], ['id' => 2]],
            [['id' => 2], ['id' => 3]],
        ]);

        $result = $handler->gather($app, 'channel_users', ['channel' => 'presence-test']);

        // Should deduplicate: 1, 2, 3
        $this->assertCount(3, $result);
    }

    public function testScalingResolvesImmediatelyWhenResponsesArriveBeforePop()
    {
        // This tests the race condition fix (Decision 16e): if all responses
        // arrive during publish() (before pop()), the handler should resolve
        // immediately without blocking on the coroutine channel.
        $app = $this->app->make(ApplicationProvider::class)->all()->first();

        $handler = $this->scalingMetricsHandler([
            ['occupied' => true, 'subscription_count' => 5],
        ]);

        $result = $handler->gather($app, 'channel', [
            'channel' => 'test-channel',
            'info' => 'occupied,subscription_count',
        ]);

        $this->assertTrue($result['occupied']);
        $this->assertSame(5, $result['subscription_count']);
    }
}
