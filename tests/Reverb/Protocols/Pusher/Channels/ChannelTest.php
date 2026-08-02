<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Server;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SubscriptionResult;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\Channel as CoroutineChannel;

use function Hypervel\Coroutine\go;

class ChannelTest extends ReverbTestCase
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
        $channel = new Channel('test-channel');

        $channel->subscribe($this->connection);

        $this->assertTrue($channel->subscribed($this->connection));
    }

    public function testCanUnsubscribeAConnectionFromAChannel(): void
    {
        $channel = new Channel('test-channel');

        $channel->subscribe($this->connection);
        $channel->unsubscribe($this->connection);

        $this->assertFalse($channel->subscribed($this->connection));
    }

    public function testRemovesAChannelWhenNoSubscribersRemain(): void
    {
        $scopedManager = m::spy(ScopedChannelManager::class);
        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);
        $this->app->singleton(ChannelManager::class, fn () => $channelManager);

        $channel = new Channel('test-channel');

        $channel->subscribe($this->connection);
        $channel->unsubscribe($this->connection);

        $scopedManager->shouldHaveReceived('remove')
            ->once()
            ->with($channel);
    }

    public function testDuplicateSubscribeAndNonmemberUnsubscribeDoNotPublishSharedState(): void
    {
        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('subscribe')
            ->once()
            ->andReturn(new SubscriptionResult(false, false, false, false, 1));
        $sharedState->shouldNotReceive('unsubscribe');
        $this->app->instance(SharedState::class, $sharedState);

        $channel = new Channel('test-channel');
        $otherConnection = new FakeConnection;

        $channel->subscribe($this->connection);
        $channel->subscribe($this->connection);
        $channel->unsubscribe($otherConnection);

        $this->assertCount(1, $channel->connections());
        $this->assertTrue($channel->subscribed($this->connection));
    }

    public function testSharedSubscribeFailureDoesNotPublishLocalMembership(): void
    {
        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('subscribe')->once()->andThrow(new RuntimeException('subscribe failed'));
        $this->app->instance(SharedState::class, $sharedState);

        $channel = $this->channels()->findOrCreate('test-channel');

        $this->expectException(RuntimeException::class);

        try {
            $channel->subscribe($this->connection);
        } finally {
            $this->assertFalse($channel->subscribed($this->connection));
            $this->assertNull($this->channels()->find('test-channel'));
        }
    }

    public function testSharedUnsubscribeFailureRetainsLocalMembership(): void
    {
        $channel = $this->channels()->findOrCreate('test-channel');
        $channel->subscribe($this->connection);

        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('unsubscribe')->once()->andThrow(new RuntimeException('unsubscribe failed'));
        $this->app->instance(SharedState::class, $sharedState);

        $this->expectException(RuntimeException::class);

        try {
            $channel->unsubscribe($this->connection);
        } finally {
            $this->assertTrue($channel->subscribed($this->connection));
            $this->assertSame($channel, $this->channels()->find('test-channel'));
        }
    }

    public function testPendingJoinKeepsAnOtherwiseEmptyChannelDiscoverable(): void
    {
        $channel = $this->channels()->findOrCreate('test-channel');
        $channel->subscribe($this->connection);

        $joiningConnection = new FakeConnection;
        $joinStarted = new CoroutineChannel(1);
        $releaseJoin = new CoroutineChannel(1);
        $joinFinished = new CoroutineChannel(1);
        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('subscribe')
            ->once()
            ->andReturnUsing(function () use ($joinStarted, $releaseJoin): SubscriptionResult {
                $joinStarted->push(true);
                $releaseJoin->pop();

                return new SubscriptionResult(false, false, false, false, 1);
            });
        $sharedState->shouldReceive('unsubscribe')
            ->once()
            ->andReturn(new SubscriptionResult(false, false, false, false, 0));
        $this->app->instance(SharedState::class, $sharedState);

        go(function () use ($channel, $joiningConnection, $joinFinished): void {
            $channel->subscribe($joiningConnection);
            $joinFinished->push(true);
        });

        $joinStarted->pop();
        $channel->unsubscribe($this->connection);

        $this->assertSame($channel, $this->channels()->find('test-channel'));
        $this->assertEmpty($channel->connections());

        $releaseJoin->push(true);

        $this->assertTrue($joinFinished->pop(1));
        $this->assertTrue($channel->subscribed($joiningConnection));
        $this->assertSame($channel, $this->channels()->find('test-channel'));
    }

    public function testFailedPendingJoinReclaimsTheIdleChannel(): void
    {
        $channel = $this->channels()->findOrCreate('test-channel');
        $joinStarted = new CoroutineChannel(1);
        $releaseJoin = new CoroutineChannel(1);
        $joinFailed = new CoroutineChannel(1);
        $sharedState = m::mock(SharedState::class);
        $sharedState->shouldReceive('subscribe')
            ->once()
            ->andReturnUsing(function () use ($joinStarted, $releaseJoin): never {
                $joinStarted->push(true);
                $releaseJoin->pop();

                throw new RuntimeException('subscribe failed');
            });
        $this->app->instance(SharedState::class, $sharedState);

        go(function () use ($channel, $joinFailed): void {
            try {
                $channel->subscribe($this->connection);
            } catch (RuntimeException) {
                $joinFailed->push(true);
            }
        });

        $joinStarted->pop();

        $this->assertSame($channel, $this->channels()->find('test-channel'));

        $releaseJoin->push(true);

        $this->assertTrue($joinFailed->pop(1));
        $this->assertNull($this->channels()->find('test-channel'));
    }

    public function testCanBroadcastToAllConnectionsOfAChannel(): void
    {
        $channel = new Channel('test-channel');
        $connections = static::factory(3);

        foreach ($connections as $connection) {
            $this->channelConnectionManager->add($connection->connection(), []);
        }

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testBroadcastAttemptsEveryConnectionAndReportsTheFirstFailure(): void
    {
        $firstFailure = new RuntimeException('first send failed');
        $secondFailure = new RuntimeException('second send failed');
        $failingConnection = static fn (RuntimeException $failure): FakeConnection => new class($failure) extends FakeConnection {
            public bool $attempted = false;

            public function __construct(private RuntimeException $failure)
            {
                parent::__construct();
            }

            public function send(string $message): void
            {
                $this->attempted = true;

                throw $this->failure;
            }
        };
        $first = $failingConnection($firstFailure);
        $middle = new FakeConnection;
        $second = $failingConnection($secondFailure);
        $last = new FakeConnection;
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->once()->with($firstFailure);
        $this->app->instance(ExceptionHandler::class, $handler);

        foreach ([$first, $middle, $second, $last] as $connection) {
            $this->channelConnectionManager->add($connection, []);
        }

        (new Channel('test-channel'))->broadcast(['foo' => 'bar']);

        $this->assertTrue($first->attempted);
        $this->assertTrue($second->attempted);
        $middle->assertReceived(['foo' => 'bar']);
        $last->assertReceived(['foo' => 'bar']);
    }

    public function testDoesNotBroadcastToTheConnectionSendingTheMessage(): void
    {
        $channel = new Channel('test-channel');
        $connections = static::factory(3);

        foreach ($connections as $connection) {
            $this->channelConnectionManager->add($connection->connection(), []);
        }

        $channel->broadcast(['foo' => 'bar'], collect($connections)->first()->connection());

        collect($connections)->first()->assertNothingReceived();
        collect(array_slice($connections, -2))->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    // ── Subscription count webhook ────────────────────────────────────

    public function testSubscribeFiresSubscriptionCountWebhook(): void
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

    public function testUnsubscribeFiresSubscriptionCountWebhook(): void
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

    public function testSubscriptionCountNotFiredWhenOptInIsFalse(): void
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

    public function testSubscriptionCountNotFiredForPresenceChannels(): void
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

    public function testSubscriptionCountNotFiredForPresenceCacheChannels(): void
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

    public function testSubscriptionCountFiredForPrivateChannels(): void
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

    public function testSubscriptionCountFiredForCacheChannels(): void
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

    public function testDisconnectDefersChannelVacatedWebhook(): void
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

    public function testExplicitUnsubscribeFiresChannelVacatedImmediately(): void
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

    public function testReconnectWithinSmoothingWindowSuppressesChannelOccupied(): void
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

    public function testNormalSubscribeFiresChannelOccupied(): void
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

    public function testCrossWorkerSmoothingMarkerSuppressesChannelOccupied(): void
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

    public function testConsumedMarkerDoesNotSuppressSubsequentLegitimateOccupied(): void
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

    public function testSubscriptionCountThrottledAbove100Subscribers(): void
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

    public function testSubscriptionCountFiresAbove100WhenLockAcquired(): void
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
