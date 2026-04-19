<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Http\Controllers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Reverb\ReverbTestCase;

class EventsControllerTest extends ReverbTestCase
{
    public function testCanReceiveAnEventTrigger()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testCanReceiveAnEventTriggerForMultipleChannels()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channels' => ['test-channel-one', 'test-channel-two'],
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testCanReturnUserCountsWhenRequested()
    {
        $this->subscribeConnection('presence-test-channel-one', ['user_id' => 1, 'user_info' => ['name' => 'Taylor']]);

        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channels' => ['presence-test-channel-one', 'test-channel-two'],
            'data' => json_encode(['some' => 'data']),
            'info' => 'user_count',
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('channels', $body);
        $this->assertSame(1, $body['channels']['presence-test-channel-one']['user_count']);
        $this->assertSame([], (array) $body['channels']['test-channel-two']);
    }

    public function testCanReturnSubscriptionCountsWhenRequested()
    {
        $this->subscribeConnection('test-channel-two');

        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channels' => ['presence-test-channel-one', 'test-channel-two'],
            'data' => json_encode(['some' => 'data']),
            'info' => 'subscription_count',
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('channels', $body);
        $this->assertSame(1, $body['channels']['test-channel-two']['subscription_count']);
        $this->assertSame([], (array) $body['channels']['presence-test-channel-one']);
    }

    public function testCanIgnoreASubscriber()
    {
        $connection = $this->subscribeConnection('test-channel');

        // First request — no socket_id exclusion, connection should receive
        $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $connection->assertReceivedCount(1);

        // Second request — exclude this socket, connection should NOT receive
        $connection->resetReceived();
        $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
            'socket_id' => $connection->id(),
        ]);

        $connection->assertNothingReceived();
    }

    public function testDoesNotFailWhenIgnoringAnInvalidSubscriber()
    {
        $connection = $this->subscribeConnection('test-channel');

        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
            'socket_id' => 'invalid-socket-id',
        ]);

        $response->assertStatus(200);

        // Connection should still receive the message (invalid socket_id doesn't match)
        $connection->assertReceivedCount(1);
    }

    public function testValidatesMissingDataField()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
        ]);

        $response->assertStatus(422);
    }

    public function testValidatesMissingNameField()
    {
        $response = $this->signedPostRequest('events', [
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(422);
    }

    public function testValidatesMissingChannelAndChannels()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(422);
    }

    public function testValidatesNonStringSocketId()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
            'socket_id' => 1234,
        ]);

        $response->assertStatus(422);
    }

    public function testValidatesNonStringInfo()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
            'info' => 1234,
        ]);

        $response->assertStatus(422);
    }

    public function testFailsWhenPayloadIsInvalid()
    {
        $response = $this->signedPostRequest('events', null);

        $response->assertStatus(500);
    }

    public function testFailsWhenAppCannotBeFound()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ], appId: 'invalid-app-id');

        $response->assertStatus(404);
    }

    public function testFailsWhenUsingAnInvalidSignature()
    {
        $response = $this->reverbCall('POST', '/apps/123456/events', [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]));

        $response->assertStatus(401);
    }

    #[DefineEnvironment('withPathPrefix')]
    public function testCanVerifySignatureWhenUsingACustomServerPath()
    {
        $appId = '123456';
        $key = 'reverb-key';
        $secret = 'reverb-secret';
        $data = ['name' => 'NewEvent', 'channel' => 'test-channel', 'data' => json_encode(['some' => 'data'])];
        $body = json_encode($data);
        $timestamp = time();

        // Build the signature WITHOUT the path prefix (as the Pusher PHP client does)
        $query = "auth_key={$key}&auth_timestamp={$timestamp}&auth_version=1.0";
        $params = explode('&', $query);
        sort($params);
        $query = implode('&', $params);
        $query .= '&body_md5=' . md5($body);

        $signatureString = "POST\n/apps/{$appId}/events\n{$query}";
        $signature = hash_hmac('sha256', $signatureString, $secret);

        // Send the request TO the prefixed URL
        $response = $this->reverbCall('POST', "/ws/apps/{$appId}/events?{$query}&auth_signature={$signature}", [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($body),
        ], $body);

        $response->assertStatus(200);
    }

    /**
     * Set the Reverb server path prefix for path prefix tests.
     */
    protected function withPathPrefix(ApplicationContract $app): void
    {
        $app['config']->set('reverb.servers.reverb.path', '/ws');
    }

    public function testReturnsEmptyObjectWhenNoInfoRequested()
    {
        $response = $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $response->assertStatus(200);
        $this->assertSame('{}', $response->getContent());
    }

    public function testBroadcastsToSubscribersOnTheChannel()
    {
        $connectionOne = $this->subscribeConnection('test-channel');
        $connectionTwo = $this->subscribeConnection('test-channel');

        $this->signedPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $connectionOne->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'test-channel',
        ]);
        $connectionTwo->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'test-channel',
        ]);
    }
}
