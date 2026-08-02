<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\MetricsRequestPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\MetricsResponsePipeMessage;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JsonException;
use Mockery as m;
use RuntimeException;
use Swoole\Server;

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

    public function testCanSendAnAcknowledgement(): void
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

    public function testCanSubscribeToAChannel(): void
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

    public function testPresenceSubscriptionSurvivesSiblingPublicationFailure(): void
    {
        $failure = new RuntimeException('Unable to reach a sibling worker.');
        $metrics = null;
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 2];
        $server->worker_id = 0;
        $server->expects('sendMessage')
            ->twice()
            ->andReturnUsing(function (object $message, int $workerId) use ($failure, &$metrics): bool {
                $this->assertSame(1, $workerId);

                if ($message instanceof ChannelBroadcastPipeMessage) {
                    throw $failure;
                }

                $this->assertInstanceOf(MetricsRequestPipeMessage::class, $message);
                $metrics->receive(new MetricsResponsePipeMessage(
                    $message->requestId,
                    ['exists' => false, 'presence' => false, 'users' => []],
                ));

                return true;
            });
        $this->app->instance(Server::class, $server);
        $metrics = $this->app->make(MetricsHandler::class);

        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->expects('report')->with($failure);
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $data = ['user_id' => '1', 'user_info' => ['name' => 'Taylor']];
        $encodedData = json_encode($data);

        $this->pusher->subscribe(
            $this->connection,
            'presence-test-channel',
            static::validAuth($this->connection->id(), 'presence-test-channel', $encodedData),
            $encodedData,
        );

        $this->connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => json_encode([
                'presence' => [
                    'count' => 1,
                    'ids' => ['1'],
                    'hash' => ['1' => ['name' => 'Taylor']],
                ],
            ]),
            'channel' => 'presence-test-channel',
        ]);
        $this->assertSame(1, $this->app->make(SharedState::class)->getSubscriptionCount(
            $this->connection->app()->id(),
            'presence-test-channel',
        ));
    }

    public function testPresenceSubscriptionSurvivesRedisPublicationFailure(): void
    {
        $failure = new RuntimeException('Redis publication failed.');
        $listener = null;
        $metricKey = null;
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->expects('on')
            ->with(m::type('string'), m::type('callable'))
            ->andReturnUsing(function (string $key, callable $callback) use (&$listener, &$metricKey): void {
                $metricKey = $key;
                $listener = $callback;
            });
        $pubSub->expects('publish')
            ->twice()
            ->andReturnUsing(function (array $payload) use ($failure, &$listener): int {
                if ($payload['type'] === 'message') {
                    throw $failure;
                }

                $this->assertSame('metrics_request', $payload['type']);
                $listener([
                    'payload' => [
                        'exists' => true,
                        'presence' => true,
                        'users' => [
                            ['user_id' => '1', 'user_info' => ['name' => 'Taylor']],
                        ],
                    ],
                ]);

                return 1;
            });
        $pubSub->expects('stopListening')
            ->with(m::on(function (string $key) use (&$metricKey): bool {
                return $key === $metricKey;
            }));
        $this->app->instance(PubSubProvider::class, $pubSub);
        $this->app->make(ServerProviderManager::class)->withPublishing();

        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->expects('report')->with($failure);
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $data = ['user_id' => '1', 'user_info' => ['name' => 'Taylor']];
        $encodedData = json_encode($data);

        $this->pusher->subscribe(
            $this->connection,
            'presence-test-channel',
            static::validAuth($this->connection->id(), 'presence-test-channel', $encodedData),
            $encodedData,
        );

        $this->connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => json_encode([
                'presence' => [
                    'count' => 1,
                    'ids' => ['1'],
                    'hash' => ['1' => ['name' => 'Taylor']],
                ],
            ]),
            'channel' => 'presence-test-channel',
        ]);
        $this->assertSame(1, $this->app->make(SharedState::class)->getSubscriptionCount(
            $this->connection->app()->id(),
            'presence-test-channel',
        ));
    }

    public function testFailedAuthorizationRemovesOnlyANewEmptyChannel(): void
    {
        $channels = $this->app->make(ChannelManager::class)->for($this->connection->app());

        try {
            $this->pusher->subscribe($this->connection, 'private-new-channel', 'invalid');
            $this->fail('Expected authorization to fail.');
        } catch (ConnectionUnauthorized) {
            $this->assertFalse($channels->exists('private-new-channel'));
        }

        $existing = $channels->findOrCreate('private-existing-channel');

        try {
            $this->pusher->subscribe($this->connection, 'private-existing-channel', 'invalid');
            $this->fail('Expected authorization to fail.');
        } catch (ConnectionUnauthorized) {
            $this->assertSame($existing, $channels->find('private-existing-channel'));
        }
    }

    public function testCanSubscribeToAnEmptyChannel(): void
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

    public function testCanUnsubscribeFromAChannel(): void
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:unsubscribe',
            ['channel' => 'test-channel']
        );

        $this->connection->assertNothingReceived();
    }

    public function testCanRespondToAPing(): void
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:ping',
        );

        $this->connection->assertReceived([
            'event' => 'pusher:pong',
        ]);
    }

    public function testCanCorrectlyFormatAPayload(): void
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

    public function testCanCorrectlyFormatAnInternalPayload(): void
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

    public function testFormatPayloadReturnsString(): void
    {
        $payload = $this->pusher->formatPayload('foo', ['bar' => 'baz']);

        $this->assertIsString($payload);
    }

    public function testFormatPayloadThrowsOnUnencodableData(): void
    {
        $this->expectException(JsonException::class);

        // NAN is not representable in JSON
        $this->pusher->formatPayload('foo', ['value' => NAN]);
    }

    // ── Cache miss webhook ────────────────────────────────────────────

    public function testCacheMissFiresWebhook(): void
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

    public function testCacheHitDoesNotFireWebhook(): void
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

    public function testCacheMissWebhookIsDeduplicated(): void
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

    public function testCacheMissWebhookRespectsEventFilter(): void
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

    public function testCacheMissWithNoWebhooksDoesNotTouchLock(): void
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
