<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JsonException;

class EventHandlerTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected EventHandler $pusher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection;
        $this->pusher = new EventHandler($this->app->make(ChannelManager::class));
    }

    public function testCanSendAnAcknowledgement()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:connection_established'
        );

        $this->connection->assertReceived([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $this->connection->id(),
                'activity_timeout' => 30,
            ]),
        ]);
    }

    public function testCanSubscribeToAChannel()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:subscribe',
            ['channel' => 'test-channel']
        );

        $this->connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'test-channel',
        ]);
    }

    public function testCanSubscribeToAnEmptyChannel()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:subscribe',
            ['channel' => '']
        );

        $this->connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
        ]);
    }

    public function testCanUnsubscribeFromAChannel()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:unsubscribe',
            ['channel' => 'test-channel']
        );

        $this->connection->assertNothingReceived();
    }

    public function testCanRespondToAPing()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:ping',
        );

        $this->connection->assertReceived([
            'event' => 'pusher:pong',
        ]);
    }

    public function testCanCorrectlyFormatAPayload()
    {
        $payload = $this->pusher->formatPayload(
            'foo',
            ['bar' => 'baz'],
            'test-channel',
        );

        $this->assertSame(json_encode([
            'event' => 'pusher:foo',
            'data' => json_encode(['bar' => 'baz']),
            'channel' => 'test-channel',
        ]), $payload);

        $payload = $this->pusher->formatPayload('foo');

        $this->assertSame(json_encode([
            'event' => 'pusher:foo',
        ]), $payload);
    }

    public function testCanCorrectlyFormatAnInternalPayload()
    {
        $payload = $this->pusher->formatInternalPayload(
            'foo',
            ['bar' => 'baz'],
            'test-channel',
        );

        $this->assertSame(json_encode([
            'event' => 'pusher_internal:foo',
            'data' => json_encode(['bar' => 'baz']),
            'channel' => 'test-channel',
        ]), $payload);

        $payload = $this->pusher->formatInternalPayload('foo');

        $this->assertSame(json_encode([
            'event' => 'pusher_internal:foo',
            'data' => '{}',
        ]), $payload);
    }

    public function testFormatPayloadReturnsString()
    {
        $payload = $this->pusher->formatPayload('foo', ['bar' => 'baz']);

        $this->assertIsString($payload);
    }

    public function testFormatPayloadThrowsOnUnencodableData()
    {
        $this->expectException(JsonException::class);

        // NAN is not representable in JSON
        $this->pusher->formatPayload('foo', ['value' => NAN]);
    }

    // ── Cache miss webhook ────────────────────────────────────────────

    public function testCacheMissFiresWebhook()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['cache_miss'],
        ]);

        $this->pusher->subscribe($this->connection, 'cache-test-channel');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'cache_miss'
                && $event['channel'] === 'cache-test-channel';
        });
    }

    public function testCacheHitDoesNotFireWebhook()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['cache_miss'],
        ]);

        // Subscribe first to create the channel, then broadcast to populate cache
        $this->pusher->subscribe($this->connection, 'cache-test-channel');

        // Reset queue to clear the cache_miss from the first subscribe
        Queue::fake();

        $channels = $this->app->make(ChannelManager::class)->for($this->connection->app());
        $channel = $channels->find('cache-test-channel');
        $channel->broadcast(['event' => 'test', 'data' => 'payload', 'channel' => 'cache-test-channel']);

        // Subscribe a new connection — cache is populated, so no cache_miss
        $secondConnection = new FakeConnection;
        $this->pusher->subscribe($secondConnection, 'cache-test-channel');

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'cache_miss';
        });
    }

    public function testCacheMissWebhookIsDeduplicated()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['cache_miss'],
        ]);

        // Two connections subscribe to the same empty cache channel
        $this->pusher->subscribe($this->connection, 'cache-test-channel');
        $secondConnection = new FakeConnection;
        $this->pusher->subscribe($secondConnection, 'cache-test-channel');

        // Only one cache_miss webhook should fire (deduplicated by lock)
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'cache_miss';
        });

        $count = Queue::pushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'cache_miss';
        })->count();

        $this->assertSame(1, $count);
    }

    public function testCacheMissWebhookRespectsEventFilter()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['channel_occupied'], // cache_miss NOT in the list
        ]);

        $this->pusher->subscribe($this->connection, 'cache-test-channel');

        Queue::assertNotPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'cache_miss';
        });
    }

    public function testCacheMissWithNoWebhooksDoesNotTouchLock()
    {
        // Default config — no webhook URL configured
        $sharedState = $this->app->make(\Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState::class);

        $this->pusher->subscribe($this->connection, 'cache-test-channel');

        // The lock should NOT have been acquired since hasWebhooks() is false.
        // Verify by acquiring it now — if it was already held, this would fail.
        $this->assertTrue(
            $sharedState->tryCacheMissLock($this->connection->app()->id(), 'cache-test-channel')
        );
    }
}
