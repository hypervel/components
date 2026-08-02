<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class EventsBatchControllerTest extends ReverbTestCase
{
    public function testCanReceiveAnEventBatchTrigger(): void
    {
        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
        ]]);

        $response->assertStatus(200);
        $this->assertSame('{"batch":{}}', $response->getContent());
    }

    public function testCanReceiveABatchWithMultipleEvents(): void
    {
        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
            ],
        ]]);

        $response->assertStatus(200);
        $this->assertSame('{"batch":{}}', $response->getContent());
    }

    public function testPublishesARemoteSocketIdFromABatchItem(): void
    {
        app(ServerProviderManager::class)->withPublishing();
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->expects('publish')->with([
            'type' => 'message',
            'app_id' => '123456',
            'payload' => [
                'event' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => '{"some":"data"}',
            ],
            'socket_id' => 'remote-socket',
        ])->andReturn(1);
        $this->app->instance(PubSubProvider::class, $pubSub);

        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
                'socket_id' => 'remote-socket',
            ],
        ]]);

        $response->assertStatus(200);
    }

    public function testCanReturnInfoForEachBatchEvent(): void
    {
        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);
        $this->subscribeConnection('test-channel-two');
        $this->subscribeConnection('test-channel-three');

        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'presence-test-channel',
                'data' => json_encode(['some' => 'data']),
                'info' => 'user_count',
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
                'info' => 'subscription_count',
            ],
            [
                'name' => 'YetAnotherNewEvent',
                'channel' => 'test-channel-three',
                'data' => json_encode(['some' => ['more' => 'data']]),
                'info' => 'subscription_count,user_count',
            ],
        ]]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('batch', $body);
        $this->assertSame(['user_count' => 1], (array) $body['batch'][0]);
        $this->assertSame(['subscription_count' => 1], (array) $body['batch'][1]);
        $this->assertSame(['subscription_count' => 1], (array) $body['batch'][2]);
    }

    public function testCanReturnInfoForSomeBatchEvents(): void
    {
        $this->subscribeConnection('presence-test-channel', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'presence-test-channel',
                'data' => json_encode(['some' => 'data']),
                'info' => 'user_count',
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
            ],
        ]]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('batch', $body);
        $this->assertSame(['user_count' => 1], (array) $body['batch'][0]);
        $this->assertSame([], (array) $body['batch'][1]);
    }

    public function testValidatesMissingBatchKey(): void
    {
        $response = $this->signedPostRequest('batch_events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(422);
    }

    public function testValidatesMissingNameInBatchItem(): void
    {
        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
        ]]);

        $response->assertStatus(422);
    }

    public function testValidatesMissingDataInBatchItem(): void
    {
        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
            ],
        ]]);

        $response->assertStatus(422);
    }

    public function testFailsWhenPayloadIsInvalid(): void
    {
        $response = $this->signedPostRequest('batch_events', null);

        $response->assertStatus(500);
    }

    public function testFailsWhenUsingAnInvalidSignature(): void
    {
        $response = $this->reverbCall('POST', '/apps/123456/batch_events', [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
        ]]));

        $response->assertStatus(401);
    }

    public function testBroadcastsBatchEventsToSubscribers(): void
    {
        $connection = $this->subscribeConnection('test-channel');

        $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'EventOne',
                'channel' => 'test-channel',
                'data' => json_encode(['first' => 'event']),
            ],
            [
                'name' => 'EventTwo',
                'channel' => 'test-channel',
                'data' => json_encode(['second' => 'event']),
            ],
        ]]);

        $connection->assertReceived([
            'event' => 'EventOne',
            'channel' => 'test-channel',
            'data' => '{"first":"event"}',
        ]);
        $connection->assertReceived([
            'event' => 'EventTwo',
            'channel' => 'test-channel',
            'data' => '{"second":"event"}',
        ]);
    }
}
