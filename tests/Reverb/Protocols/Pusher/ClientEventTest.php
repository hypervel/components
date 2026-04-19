<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Hypervel\Reverb\Protocols\Pusher\ClientEvent;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class ClientEventTest extends ReverbTestCase
{
    protected ChannelConnectionManager $channelConnectionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channelConnectionManager = m::spy(ChannelConnectionManager::class);
        $this->channelConnectionManager->shouldReceive('for')
            ->andReturn($this->channelConnectionManager);
        $this->app->bind(ChannelConnectionManager::class, fn () => $this->channelConnectionManager);
    }

    public function testCanForwardAClientMessage()
    {
        $this->channels()->findOrCreate('private-test-channel');

        $connectionOne = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();
        $connectionTwo = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '2']))->first();

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn($connectionOne);
        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$connectionOne, $connectionTwo]);

        ClientEvent::handle(
            $connectionOne->connection(),
            [
                'event' => 'client-test-message',
                'channel' => 'private-test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        $connectionOne->connection()->assertNothingReceived();
        $connectionTwo->connection()->assertReceived([
            'event' => 'client-test-message',
            'channel' => 'private-test-channel',
            'data' => ['foo' => 'bar'],
            'user_id' => '1',
        ]);
    }

    public function testRejectClientEventOnPublicChannelInMembersMode()
    {
        $this->channels()->findOrCreate('test-channel');

        $connections = static::factory(3);

        $connection = $connections[0]->connection();

        ClientEvent::handle(
            $connection,
            [
                'event' => 'client-test-message',
                'channel' => 'test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4301,
                'message' => 'Client events are only supported on private and presence channels.',
            ]),
        ]);

        // Other connections should not receive the event
        $connections[1]->connection()->assertNothingReceived();
        $connections[2]->connection()->assertNothingReceived();
    }

    public function testAllowsClientEventOnPublicChannelInAllMode()
    {
        $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'all');
        $this->channels()->findOrCreate('test-channel');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory(3));

        ClientEvent::handle(
            $connections[0]->connection(),
            [
                'event' => 'client-test-message',
                'channel' => 'test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        $connections[0]->connection()->assertNothingReceived();
        $connections[1]->connection()->assertReceived([
            'event' => 'client-test-message',
            'channel' => 'test-channel',
            'data' => ['foo' => 'bar'],
        ]);
    }

    public function testRejectClientEventOnPublicChannelDoesNotProduceWebhook()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['client_event'],
        ]);

        $this->channels()->findOrCreate('test-channel');

        $connection = new FakeConnection;

        ClientEvent::handle(
            $connection,
            [
                'event' => 'client-test-message',
                'channel' => 'test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        Queue::assertNotPushed(WebhookDeliveryJob::class);
    }

    public function testDoesNotForwardUnauthenticatedClientMessageWhenInMembersMode()
    {
        $this->channels()->findOrCreate('private-test-channel');

        $connectionOne = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();
        $connectionTwo = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '2']))->first();

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn(null);
        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$connectionTwo]);

        ClientEvent::handle(
            $connectionOne->connection(),
            [
                'event' => 'client-test-message',
                'channel' => 'private-test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        $connectionOne->connection()->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4009,
                'message' => 'The client is not a member of the specified channel.',
            ]),
        ]);
        $connectionTwo->connection()->assertNothingReceived();
    }

    public function testDoesNotForwardClientMessageWhenSetToNone()
    {
        $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'none');
        $this->channels()->findOrCreate('private-test-channel');

        $connectionOne = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();
        $connectionTwo = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '2']))->first();

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn($connectionOne);
        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$connectionOne, $connectionTwo]);

        ClientEvent::handle(
            $connectionOne->connection(),
            [
                'event' => 'client-test-message',
                'channel' => 'private-test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        $connectionOne->connection()->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4301,
                'message' => 'The app does not have client messaging enabled.',
            ]),
        ]);
        $connectionTwo->connection()->assertNothingReceived();
    }

    public function testForwardsAClientMessageForUnauthenticatedClientWhenSetToAll()
    {
        $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'all');
        $connection = new FakeConnection;
        $this->channels()->findOrCreate('test-channel');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn($connections = static::factory());

        ClientEvent::handle(
            $connection,
            [
                'event' => 'client-test-message',
                'channel' => 'test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        collect($connections)->first()->assertReceived([
            'event' => 'client-test-message',
            'channel' => 'test-channel',
            'data' => ['foo' => 'bar'],
        ]);
    }

    public function testDoesNotForwardAMessageToItself()
    {
        $connection = new ChannelConnection(new FakeConnection);
        $this->channels()->findOrCreate('private-test-channel');

        $this->channelConnectionManager->shouldReceive('all')
            ->once()
            ->andReturn([$connection]);
        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn($connection);

        ClientEvent::handle(
            $connection->connection(),
            [
                'event' => 'client-test-message',
                'channel' => 'private-test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        $connection->connection()->assertNothingReceived();
    }

    public function testWebhookIncludesUserIdForPresenceChannelInAllMode()
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'all');
        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['client_event'],
        ]);

        $connectionData = ['user_info' => ['name' => 'Taylor'], 'user_id' => '42'];
        $channelConnection = collect(static::factory(data: $connectionData))->first();

        $this->channels()->findOrCreate('presence-test-channel');

        $this->channelConnectionManager->shouldReceive('find')
            ->andReturn($channelConnection);
        $this->channelConnectionManager->shouldReceive('all')
            ->andReturn([$channelConnection]);

        ClientEvent::handle(
            $channelConnection->connection(),
            [
                'event' => 'client-test-message',
                'channel' => 'presence-test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            $event = $job->payload->events[0];

            return $event['name'] === 'client_event'
                && $event['user_id'] === '42'
                && $event['channel'] === 'presence-test-channel';
        });
    }

    public function testFailsOnUnsupportedMessage()
    {
        $this->channels()->findOrCreate('test-channel');

        $connection = new FakeConnection;

        $this->channelConnectionManager->shouldNotReceive('hydratedConnections');

        ClientEvent::handle(
            $connection,
            [
                'event' => 'test-message',
                'channel' => 'test-channel',
                'data' => ['foo' => 'bar'],
            ]
        );
    }
}
