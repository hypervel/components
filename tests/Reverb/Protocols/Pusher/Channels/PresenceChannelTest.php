<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\Channels\PresenceChannel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class PresenceChannelTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected ChannelConnectionManager $channelConnectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection;
        $this->channelConnectionManager = m::mock(ArrayChannelConnectionManager::class)->makePartial();
        $this->app->bind(ChannelConnectionManager::class, fn () => $this->channelConnectionManager);
    }

    public function testCanSubscribeAConnectionToAChannel(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-test-channel'));

        $this->assertTrue($channel->subscribed($this->connection));
    }

    public function testCanUnsubscribeAConnectionFromAChannel(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-test-channel'));
        $channel->unsubscribe($this->connection);

        $this->assertFalse($channel->subscribed($this->connection));
    }

    public function testCanBroadcastToAllConnectionsOfAChannel(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory(3));

        $channel->broadcast(['foo' => 'bar']);

        collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
    }

    public function testFailsToSubscribeIfTheSignatureIsInvalid(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $this->expectException(ConnectionUnauthorized::class);

        try {
            $channel->subscribe($this->connection, 'invalid-signature');
        } finally {
            $this->assertFalse($channel->subscribed($this->connection));
        }
    }

    public function testCanReturnDataStoredOnTheConnection(): void
    {
        $channel = $this->channels()->findOrCreate('presence-test-channel');

        $connections = [
            collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first(),
            collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 2]))->first(),
        ];

        $this->channelConnectionManager->shouldReceive('all')
            ->twice()
            ->andReturn($connections);

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
        $channel = $this->channels()->findOrCreate('presence-test-channel');

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, []);

        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn($connections = static::factory(3));

        $channel->subscribe($this->connection, static::validAuth($this->connection->id(), 'presence-test-channel'));

        collect($connections)->each(fn ($connection) => $connection->assertReceived([
            'event' => 'pusher_internal:member_added',
            'data' => '{}',
            'channel' => 'presence-test-channel',
        ]));
    }

    public function testSendsNotificationOfSubscriptionWithData(): void
    {
        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['name' => 'Joe']);

        $this->channelConnectionManager->shouldReceive('add')
            ->once()
            ->with($this->connection, ['name' => 'Joe']);

        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn($connections = static::factory(3));

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        collect($connections)->each(fn ($connection) => $connection->assertReceived([
            'event' => 'pusher_internal:member_added',
            'data' => json_encode(['name' => 'Joe']),
            'channel' => 'presence-test-channel',
        ]));
    }

    public function testSendsNotificationOfAnUnsubscribe(): void
    {
        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Joe'], 'user_id' => 1]);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn(new ChannelConnection($this->connection, ['user_info' => ['name' => 'Joe'], 'user_id' => 1]));

        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn($connections = static::factory(3));

        $this->channelConnectionManager->shouldReceive('remove')
            ->once()
            ->with($this->connection);

        $channel->unsubscribe($this->connection);

        collect($connections)->each(fn ($connection) => $connection->assertReceived([
            'event' => 'pusher_internal:member_removed',
            'data' => json_encode(['user_id' => '1']),
            'channel' => 'presence-test-channel',
        ]));
    }

    public function testSubscriptionAndUnsubscriptionPreserveZeroUserId(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_added', 'member_removed'],
            'disconnect_smoothing_ms' => 0,
        ]);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Zero'], 'user_id' => 0]);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data,
        );

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'member_added'
                && $event['user_id'] === '0';
        });

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn(new ChannelConnection($this->connection, ['user_info' => ['name' => 'Zero'], 'user_id' => 0]));
        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([]);

        Queue::fake();

        $channel->unsubscribe($this->connection);

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'member_removed'
                && $event['user_id'] === '0';
        });
    }

    public function testEnsuresTheMemberAddedEventIsOnlyFiredOnce(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $connectionOne = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first();
        $connectionTwo = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first();

        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$connectionOne, $connectionTwo]);

        $channel->subscribe($connectionOne->connection(), static::validAuth($connectionOne->id(), 'presence-test-channel', $data = json_encode($connectionOne->data())), $data);
        $channel->subscribe($connectionTwo->connection(), static::validAuth($connectionTwo->id(), 'presence-test-channel', $data = json_encode($connectionTwo->data())), $data);

        // Second subscribe for same user_id should NOT trigger member_added broadcast
        $connectionOne->connection()->assertNothingReceived();
    }

    public function testEnsuresTheMemberRemovedEventIsOnlyFiredOnce(): void
    {
        $channel = new PresenceChannel('presence-test-channel');

        $connectionOne = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first();
        $connectionTwo = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first();

        // Subscribe both so SharedState has refcount of 2 for user_id 1
        $channel->subscribe($connectionOne->connection(), static::validAuth($connectionOne->id(), 'presence-test-channel', $data = json_encode($connectionOne->data())), $data);
        $channel->subscribe($connectionTwo->connection(), static::validAuth($connectionTwo->id(), 'presence-test-channel', $data = json_encode($connectionTwo->data())), $data);

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn($connectionTwo);

        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$connectionOne, $connectionTwo]);

        // First unsubscribe — user still has another connection, so no member_removed
        $channel->unsubscribe($connectionTwo->connection());

        $connectionOne->connection()->assertNothingReceived();
    }

    // ── Disconnect smoothing ──────────────────────────────────────────

    public function testDisconnectDefersMemberRemovedWebhook(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_removed'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn(new ChannelConnection($this->connection, ['user_info' => ['name' => 'Test'], 'user_id' => '1']));

        Queue::fake();

        // Simulate disconnect — markDisconnecting then unsubscribe
        $this->connection->markDisconnecting();
        $channel->unsubscribe($this->connection);

        // Webhook should NOT fire immediately — it's deferred
        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_removed';
        });
    }

    public function testExplicitUnsubscribeFiresMemberRemovedImmediately(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_removed'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn(new ChannelConnection($this->connection, ['user_info' => ['name' => 'Test'], 'user_id' => '1']));

        Queue::fake();

        // Explicit unsubscribe — isDisconnecting is false
        $channel->unsubscribe($this->connection);

        // Webhook should fire immediately
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_removed';
        });
    }

    // ── Reconnect suppression ─────────────────────────────────────────

    public function testReconnectWithinSmoothingWindowSuppressesMemberAddedWebhook(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_added', 'member_removed'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        // Set up mock for unsubscribe's find() call
        // Simulate disconnect
        $this->connection->markDisconnecting();
        $channel->unsubscribe($this->connection);

        // Reset queue to isolate the reconnect
        Queue::fake();

        // Reconnect with same user — should suppress member_added webhook
        $newConnection = new FakeConnection;
        $newData = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $channel->subscribe(
            $newConnection,
            static::validAuth($newConnection->id(), 'presence-test-channel', $newData),
            $newData
        );

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_added';
        });
    }

    public function testReconnectStillSendsInternalMemberAddedEvent(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_added', 'member_removed'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        // Simulate disconnect
        $this->connection->markDisconnecting();
        $channel->unsubscribe($this->connection);

        // Set up mock to return connections for the internal broadcast
        $listener = new FakeConnection;
        $listenerChannelConnection = new ChannelConnection($listener);
        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$listenerChannelConnection]);

        // Reconnect
        $newConnection = new FakeConnection;
        $newData = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $channel->subscribe(
            $newConnection,
            static::validAuth($newConnection->id(), 'presence-test-channel', $newData),
            $newData
        );

        // Internal pusher_internal:member_added event should still fire
        // even though the webhook is suppressed
        $listener->assertReceived([
            'event' => 'pusher_internal:member_added',
            'data' => json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']),
            'channel' => 'presence-test-channel',
        ]);
    }

    public function testCrossWorkerSmoothingMarkerSuppressesMemberAdded(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_added'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        // Simulate a marker set by another worker's disconnect
        $sharedState = $this->app->make(SharedState::class);
        $sharedState->setMemberSmoothingPending('123456', 'presence-test-channel', '1', 3000);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        // member_added webhook should be suppressed by the shared marker
        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_added';
        });
    }

    public function testConsumedMemberMarkerDoesNotSuppressSubsequentLegitimateAdd(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['member_added', 'member_removed'],
            'disconnect_smoothing_ms' => 3000,
        ]);

        // Set marker (simulating another worker's disconnect)
        $sharedState = $this->app->make(SharedState::class);
        $sharedState->setMemberSmoothingPending('123456', 'presence-test-channel', '1', 3000);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $data = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        // Subscribe — consumes the marker, suppresses member_added
        $channel->subscribe(
            $this->connection,
            static::validAuth($this->connection->id(), 'presence-test-channel', $data),
            $data
        );

        // Explicit unsubscribe — fires member_removed immediately, no new marker
        $channel->unsubscribe($this->connection);

        // Reset queue
        Queue::fake();

        // New subscribe — marker was consumed, should fire member_added normally
        $newConnection = new FakeConnection;
        $newData = json_encode(['user_info' => ['name' => 'Test'], 'user_id' => '1']);

        $channel = $this->channels()->findOrCreate('presence-test-channel');
        $channel->subscribe(
            $newConnection,
            static::validAuth($newConnection->id(), 'presence-test-channel', $newData),
            $newData
        );

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'member_added';
        });
    }
}
