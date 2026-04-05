<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Tests\Reverb\ReverbTestCase;

/**
 * @internal
 * @coversNothing
 */
class EventsBatchControllerTest extends ReverbTestCase
{
    public function testCanReceiveAnEventBatchTrigger()
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

    public function testCanReceiveABatchWithMultipleEvents()
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

    public function testCanReturnInfoForEachBatchEvent()
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

    public function testCanReturnInfoForSomeBatchEvents()
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

    public function testValidatesMissingBatchKey()
    {
        $response = $this->signedPostRequest('batch_events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(422);
    }

    public function testValidatesMissingNameInBatchItem()
    {
        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
        ]]);

        $response->assertStatus(422);
    }

    public function testValidatesMissingDataInBatchItem()
    {
        $response = $this->signedPostRequest('batch_events', ['batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
            ],
        ]]);

        $response->assertStatus(422);
    }

    public function testFailsWhenPayloadIsInvalid()
    {
        $response = $this->signedPostRequest('batch_events', null);

        $response->assertStatus(500);
    }

    public function testFailsWhenUsingAnInvalidSignature()
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

    public function testBroadcastsBatchEventsToSubscribers()
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
