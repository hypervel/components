<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Server;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SubscriptionResult;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
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

    // ── Subscription count webhook ────────────────────────────────────

    public function testSubscribeFiresSubscriptionCountWebhook()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        $this->subscribeConnection('test-channel');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'subscription_count'
                && $event['channel'] === 'test-channel'
                && $event['subscription_count'] === 1;
        });
    }

    public function testUnsubscribeFiresSubscriptionCountWebhook()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
            'disconnect_smoothing_ms' => 0,
        ]);

        $connection1 = $this->subscribeConnection('test-channel');
        $connection2 = $this->subscribeConnection('test-channel');

        // Reset to isolate the unsubscribe webhook
        Queue::fake();

        $this->channels()->find('test-channel')->unsubscribe($connection1);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'subscription_count'
                && $event['subscription_count'] === 1;
        });
    }

    public function testSubscriptionCountNotFiredWhenOptInIsFalse()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            // subscription_count not set — defaults to false
        ]);

        $this->subscribeConnection('test-channel');

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    public function testSubscriptionCountNotFiredForPresenceChannels()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        $this->subscribeConnection('presence-test', ['user_id' => '1', 'user_info' => ['name' => 'Test']]);

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    public function testSubscriptionCountNotFiredForPresenceCacheChannels()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        $this->subscribeConnection('presence-cache-test', ['user_id' => '1', 'user_info' => ['name' => 'Test']]);

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    public function testSubscriptionCountFiredForPrivateChannels()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        $this->subscribeConnection('private-test');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    public function testSubscriptionCountFiredForCacheChannels()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        $this->subscribeConnection('cache-test');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    // ── Disconnect smoothing ──────────────────────────────────────────

    public function testDisconnectDefersChannelVacatedWebhook()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_vacated'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        $connection = $this->subscribeConnection('test-channel');
        Queue::fake();

        // Simulate disconnect (Server::close sets isDisconnecting)
        $server = $this->app->make(Server::class);
        $server->close($connection);

        // Webhook should NOT fire immediately — it's deferred
        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_vacated';
        });
    }

    public function testExplicitUnsubscribeFiresChannelVacatedImmediately()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_vacated'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        $connection = $this->subscribeConnection('test-channel');
        Queue::fake();

        // Explicit unsubscribe via EventHandler (isDisconnecting is false)
        $handler = new EventHandler($this->app->make(ChannelManager::class));
        $handler->unsubscribe($connection, 'test-channel');

        // Webhook should fire immediately — no deferral for explicit unsubscribe
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_vacated';
        });
    }

    // ── Reconnect suppression ─────────────────────────────────────────

    public function testReconnectWithinSmoothingWindowSuppressesChannelOccupied()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied', 'channel_vacated'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        // Subscribe, then disconnect (sets smoothing marker + defers vacated)
        $connection = $this->subscribeConnection('test-channel');
        $server = $this->app->make(Server::class);
        $server->close($connection);

        // Reset queue to isolate the reconnect
        Queue::fake();

        // Reconnect — should suppress channel_occupied
        $this->subscribeConnection('test-channel');

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_occupied';
        });
    }

    public function testNormalSubscribeFiresChannelOccupied()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        // First subscribe — no prior disconnect, no smoothing marker
        $this->subscribeConnection('test-channel');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_occupied';
        });
    }

    public function testCrossWorkerSmoothingMarkerSuppressesChannelOccupied()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        // Simulate a marker set by another worker's disconnect
        // (no local timer — cancelChannelVacated will return false)
        $sharedState = $this->app->make(SharedState::class);
        $sharedState->setSmoothingPending('123456', 'test-channel', 3000);

        // Also need SharedState to reflect the channel going 0→1
        // (the other worker decremented to 0, now we increment to 1)
        $this->subscribeConnection('test-channel');

        // channel_occupied should be suppressed by the shared marker alone
        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_occupied';
        });
    }

    public function testConsumedMarkerDoesNotSuppressSubsequentLegitimateOccupied()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied', 'channel_vacated'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        // Set marker (simulating another worker's disconnect)
        $sharedState = $this->app->make(SharedState::class);
        $sharedState->setSmoothingPending('123456', 'test-channel', 3000);

        // Subscribe — consumes the marker, suppresses channel_occupied
        $connection = $this->subscribeConnection('test-channel');

        // Explicit unsubscribe — fires channel_vacated immediately, no new marker
        $handler = new EventHandler($this->app->make(ChannelManager::class));
        $handler->unsubscribe($connection, 'test-channel');

        // Reset queue
        Queue::fake();

        // New subscribe — marker was consumed, should fire channel_occupied normally
        $this->subscribeConnection('test-channel');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'channel_occupied';
        });
    }

    // ── Subscription count throttling ─────────────────────────────────

    public function testSubscriptionCountThrottledAbove100Subscribers()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        // Mock SharedState to return count >100 and lock already held
        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('subscribe')
            ->andReturn(new SubscriptionResult(
                channelOccupied: true,
                channelVacated: false,
                memberAdded: false,
                memberRemoved: false,
                subscriptionCount: 150,
            ));
        $sharedState->shouldReceive('trySubscriptionCountLock')
            ->with('123456', 'test-channel')
            ->andReturn(false);
        $sharedState->shouldReceive('clearSmoothingPending')->andReturn(false);
        $this->app->instance(SharedState::class, $sharedState);

        $channel = new Channel('test-channel');
        $channel->subscribe($this->connection);

        // subscription_count should be suppressed (lock held)
        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'subscription_count';
        });
    }

    public function testSubscriptionCountFiresAbove100WhenLockAcquired()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => [],
            'subscription_count' => true,
        ]);

        // Mock SharedState to return count >100 and lock acquired
        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('subscribe')
            ->andReturn(new SubscriptionResult(
                channelOccupied: true,
                channelVacated: false,
                memberAdded: false,
                memberRemoved: false,
                subscriptionCount: 150,
            ));
        $sharedState->shouldReceive('trySubscriptionCountLock')
            ->with('123456', 'test-channel')
            ->andReturn(true);
        $sharedState->shouldReceive('clearSmoothingPending')->andReturn(false);
        $this->app->instance(SharedState::class, $sharedState);

        $channel = new Channel('test-channel');
        $channel->subscribe($this->connection);

        // subscription_count should fire with count 150
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'subscription_count'
                && $event['subscription_count'] === 150;
        });
    }
}
