<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * End-to-end integration tests for the Reverb WebSocket server.
 *
 * Requires a running test server: php tests/Integration/Reverb/server.php
 *
 * @internal
 * @coversNothing
 */
class ServerTest extends ReverbIntegrationTestCase
{
    // ── Connection lifecycle ────────────────────────────────────────────

    public function testCanConnectAndReceiveConnectionEstablished()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $this->assertNotEmpty($socketId);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $socketId);

        $this->disconnect($client);
    }

    public function testFailsToConnectWithInvalidAppKey()
    {
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->upgrade('/app/invalid-key');

        $frame = $client->recv(3);

        $this->assertNotFalse($frame);
        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:error', $data['event']);

        $errorData = json_decode($data['data'], associative: true);
        $this->assertSame(4001, $errorData['code']);
        $this->assertSame('Application does not exist', $errorData['message']);

        $client->close();
    }

    // ── Channel subscriptions ──────────────────────────────────────────

    public function testCanSubscribeToAPublicChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'test-channel');

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);
        $this->assertSame('test-channel', $data['channel']);

        // Verify via HTTP API that the channel is occupied
        $result = $this->signedServerRequest('channels/test-channel?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);

        $this->disconnect($client);
    }

    public function testCanSubscribeToAPrivateChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'private-test-channel');

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);
        $this->assertSame('private-test-channel', $data['channel']);

        $this->disconnect($client);
    }

    public function testCanSubscribeToAPresenceChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'presence-test-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);
        $this->assertStringContainsString('Test User', $data['data']);

        $this->disconnect($client);
    }

    public function testCanSubscribeToACacheChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'cache-test-channel');

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);

        // Should also receive cache_miss since no cached payload
        $miss = $this->recv($client, 0.1);
        if ($miss !== null) {
            $missData = json_decode($miss, associative: true);
            $this->assertSame('pusher:cache_miss', $missData['event']);
        }

        $this->disconnect($client);
    }

    public function testCanSubscribeToAPrivateCacheChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'private-cache-test-channel');

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);

        $this->disconnect($client);
    }

    public function testCanSubscribeToAPresenceCacheChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'presence-cache-test-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);

        $this->disconnect($client);
    }

    public function testFailsToSubscribeToPrivateChannelWithInvalidAuth()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'private-test-channel',
                'auth' => 'invalid-signature',
            ],
        ]));

        $response = $this->recv($client);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $this->disconnect($client);
    }

    public function testFailsToSubscribeToPresenceChannelWithInvalidAuth()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'presence-test-channel',
                'auth' => 'invalid-signature',
            ],
        ]));

        $response = $this->recv($client);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $this->disconnect($client);
    }

    public function testFailsToSubscribeToPrivateChannelWithNullAuth()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'private-test-channel',
                'auth' => null,
            ],
        ]));

        $response = $this->recv($client);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $this->disconnect($client);
    }

    public function testFailsToSubscribeToPrivateCacheChannelWithInvalidAuth()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'private-cache-test-channel',
                'auth' => 'invalid-signature',
            ],
        ]));

        $response = $this->recv($client);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $this->disconnect($client);
    }

    public function testFailsToSubscribeToPresenceCacheChannelWithInvalidAuth()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'presence-cache-test-channel',
                'auth' => 'invalid-signature',
            ],
        ]));

        $response = $this->recv($client);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $this->disconnect($client);
    }

    public function testFailsToSubscribeToPresenceChannelWithNullAuth()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'presence-test-channel',
                'auth' => null,
            ],
        ]));

        $response = $this->recv($client);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $this->disconnect($client);
    }

    // ── Broadcasting ───────────────────────────────────────────────────

    public function testCanReceiveABroadcastFromTheServer()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'test-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'test-channel');

        $this->triggerEvent('test-channel', 'App\Events\TestEvent', ['foo' => 'bar']);

        $msgOne = $this->recv($clientOne);
        $msgTwo = $this->recv($clientTwo);

        $this->assertNotNull($msgOne);
        $this->assertNotNull($msgTwo);

        $dataOne = json_decode($msgOne, associative: true);
        $this->assertSame('App\Events\TestEvent', $dataOne['event']);
        $this->assertSame('test-channel', $dataOne['channel']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testCanHandleAnEventOnPresenceChannel()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-event-test-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        $this->triggerEvent(
            'presence-event-test-channel',
            'App\Events\TestEvent',
            ['foo' => 'bar'],
        );

        $msg = $this->recv($client);
        $this->assertNotNull($msg);

        $data = json_decode($msg, associative: true);
        $this->assertSame('App\Events\TestEvent', $data['event']);
        $this->assertSame('presence-event-test-channel', $data['channel']);

        $this->disconnect($client);
    }

    public function testCanReceiveACachedMessageWhenJoiningCacheChannel()
    {
        // First subscriber + trigger to create cached payload
        ['client' => $firstClient, 'socketId' => $firstSocketId] = $this->connect();
        $this->subscribe($firstClient, $firstSocketId, 'cache-test-channel-cached');

        $this->triggerEvent('cache-test-channel-cached', 'App\Events\TestEvent', ['foo' => 'bar']);

        // Drain the broadcast the first client receives
        $this->recv($firstClient, 0.1);

        // Second subscriber should get the cached payload
        ['client' => $secondClient, 'socketId' => $secondSocketId] = $this->connect();
        $this->subscribe($secondClient, $secondSocketId, 'cache-test-channel-cached');

        // After subscription_succeeded, should receive the cached event
        $cached = $this->recv($secondClient, 2);
        $this->assertNotNull($cached, 'Expected cached message');

        $data = json_decode($cached, associative: true);
        $this->assertSame('App\Events\TestEvent', $data['event']);

        $this->disconnect($firstClient);
        $this->disconnect($secondClient);
    }

    public function testCanReceiveACachedMessageWhenJoiningPrivateCacheChannel()
    {
        ['client' => $firstClient, 'socketId' => $firstSocketId] = $this->connect();
        $this->subscribe($firstClient, $firstSocketId, 'private-cache-test-channel-cached');

        $this->triggerEvent('private-cache-test-channel-cached', 'App\Events\TestEvent', ['foo' => 'bar']);
        $this->recv($firstClient, 0.1);

        ['client' => $secondClient, 'socketId' => $secondSocketId] = $this->connect();
        $this->subscribe($secondClient, $secondSocketId, 'private-cache-test-channel-cached');

        $cached = $this->recv($secondClient, 2);
        $this->assertNotNull($cached, 'Expected cached message on private-cache channel');
        $data = json_decode($cached, associative: true);
        $this->assertSame('App\Events\TestEvent', $data['event']);

        $this->disconnect($firstClient);
        $this->disconnect($secondClient);
    }

    public function testCanReceiveACachedMessageWhenJoiningPresenceCacheChannel()
    {
        ['client' => $firstClient, 'socketId' => $firstSocketId] = $this->connect();
        $this->subscribe($firstClient, $firstSocketId, 'presence-cache-test-channel-cached', [
            'user_id' => 1,
            'user_info' => ['name' => 'User 1'],
        ]);

        $this->triggerEvent('presence-cache-test-channel-cached', 'App\Events\TestEvent', ['foo' => 'bar']);
        $this->recv($firstClient, 0.1);

        ['client' => $secondClient, 'socketId' => $secondSocketId] = $this->connect();
        $this->subscribe($secondClient, $secondSocketId, 'presence-cache-test-channel-cached', [
            'user_id' => 2,
            'user_info' => ['name' => 'User 2'],
        ]);

        // After subscription_succeeded, the next frame is the cached payload.
        // (member_added goes to OTHER subscribers, not the joining client.)
        $cached = $this->recv($secondClient, 2);
        $this->assertNotNull($cached, 'Expected cached message on presence-cache channel');
        $data = json_decode($cached, associative: true);
        $this->assertSame('App\Events\TestEvent', $data['event']);

        $this->disconnect($firstClient);
        $this->disconnect($secondClient);
    }

    public function testReceivesCacheMissWhenNoCachedPayload()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'cache-empty-channel');

        $miss = $this->recv($client, 0.1);
        $this->assertNotNull($miss);

        $data = json_decode($miss, associative: true);
        $this->assertSame('pusher:cache_miss', $data['event']);
        $this->assertSame('cache-empty-channel', $data['channel']);

        $this->disconnect($client);
    }

    public function testReceivesCacheMissOnPrivateCacheChannelWithEmptyCache()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'private-cache-empty-channel');

        $miss = $this->recv($client, 0.1);
        $this->assertNotNull($miss);

        $data = json_decode($miss, associative: true);
        $this->assertSame('pusher:cache_miss', $data['event']);
        $this->assertSame('private-cache-empty-channel', $data['channel']);

        $this->disconnect($client);
    }

    public function testReceivesCacheMissOnPresenceCacheChannelWithEmptyCache()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-cache-empty-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'User'],
        ]);

        $miss = $this->recv($client, 0.1);
        $this->assertNotNull($miss);

        $data = json_decode($miss, associative: true);
        $this->assertSame('pusher:cache_miss', $data['event']);
        $this->assertSame('presence-cache-empty-channel', $data['channel']);

        $this->disconnect($client);
    }

    // ── Presence channel notifications ─────────────────────────────────

    public function testNotifiesSubscribersWhenPresenceMemberJoins()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-notify-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'User 1'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-notify-channel', [
            'user_id' => 2,
            'user_info' => ['name' => 'User 2'],
        ]);

        // Client one should receive member_added for user 2
        $msg = $this->recv($clientOne);
        $this->assertNotNull($msg);
        $data = json_decode($msg, associative: true);
        $this->assertSame('pusher_internal:member_added', $data['event']);
        $this->assertStringContainsString('User 2', $data['data']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testNotifiesSubscribersWhenPresenceMemberLeaves()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-leave-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'User 1'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-leave-channel', [
            'user_id' => 2,
            'user_info' => ['name' => 'User 2'],
        ]);

        // Drain the member_added notification on client one
        $this->recv($clientOne, 0.1);

        // Disconnect client two
        $this->disconnect($clientTwo);

        // Client one should receive member_removed for user 2
        $msg = $this->recv($clientOne, 3);
        $this->assertNotNull($msg, 'Expected member_removed notification');
        $data = json_decode($msg, associative: true);
        $this->assertSame('pusher_internal:member_removed', $data['event']);
        $this->assertStringContainsString('"user_id":2', $data['data']);

        $this->disconnect($clientOne);
    }

    public function testSubscriptionSucceededContainsUniqueUserList()
    {
        // Two connections for the same user
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-unique-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $response = $this->subscribe($clientTwo, $socketIdTwo, 'presence-unique-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        $data = json_decode($response, associative: true);
        $presenceData = json_decode($data['data'], associative: true);
        $this->assertSame(1, $presenceData['presence']['count']);
        $this->assertSame([1], $presenceData['presence']['ids']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── Ping/pong ──────────────────────────────────────────────────────

    public function testCanRespondToAPusherPing()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->send($client, ['event' => 'pusher:ping']);

        $this->assertNotNull($response);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher:pong', $data['event']);

        $this->disconnect($client);
    }

    public function testCanHandleWebSocketPingControlFrame()
    {
        // Must enable pong frame reception on the client to see the pong response
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->set(['open_websocket_pong_frame' => true]);
        $client->upgrade('/app/' . $this->appKey);

        // Drain connection_established
        $client->recv(3);

        // Send a WebSocket ping frame
        $client->push('', WEBSOCKET_OPCODE_PING);

        // Should receive a pong frame back
        $frame = $client->recv(3);
        $this->assertInstanceOf(\Swoole\WebSocket\Frame::class, $frame);
        $this->assertSame(WEBSOCKET_OPCODE_PONG, $frame->opcode);

        $client->close();
    }

    public function testCanHandleWebSocketPongControlFrame()
    {
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->set(['open_websocket_pong_frame' => true]);
        $client->upgrade('/app/' . $this->appKey);

        // Drain connection_established
        $client->recv(3);

        // Send a WebSocket pong frame
        $client->push('', WEBSOCKET_OPCODE_PONG);

        // No response should come back — pong is a one-way acknowledgment
        $frame = $client->recv(0.5);
        $this->assertFalse($frame);

        $client->close();
    }

    // ── Control frame preference ──────────────────────────────────────

    public function testUsesPusherControlMessagesByDefault()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-ping-default-channel');

        // Trigger inactive ping via test endpoint (ages connections + runs PingInactiveConnections)
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/ping-inactive/' . $this->appId, '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();

        // Should receive a Pusher-level ping (not a WS control frame)
        $msg = $this->recv($client, 2);
        $this->assertNotNull($msg, 'Expected Pusher-level ping');
        $data = json_decode($msg, associative: true);
        $this->assertSame('pusher:ping', $data['event']);

        $this->disconnect($client);
    }

    public function testUsesControlFramesWhenClientPrefers()
    {
        // Enable both ping and pong frame reception on the client:
        // - pong: to receive the pong response to our initial ping
        // - ping: to receive the server-sent WS ping from PingInactiveConnections
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->set([
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
        ]);
        $client->upgrade('/app/' . $this->appKey);

        // Drain connection_established
        $established = $client->recv(3);
        $socketId = json_decode(json_decode($established->data, true)['data'], true)['socket_id'];

        // Send a WS ping to signal control frame support
        $client->push('', WEBSOCKET_OPCODE_PING);
        // Drain the pong response
        $client->recv(0.1);

        // Subscribe to a channel
        $this->subscribe($client, $socketId, 'test-ping-control-channel');

        // Trigger inactive ping
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/ping-inactive/' . $this->appId, '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();

        // Should receive a WS ping control frame (not Pusher-level)
        $frame = $client->recv(2);
        $this->assertInstanceOf(\Swoole\WebSocket\Frame::class, $frame);
        $this->assertSame(WEBSOCKET_OPCODE_PING, $frame->opcode);

        $client->close();
    }

    public function testCanDisconnectInactiveSubscribers()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-prune-channel');

        // Step 1: age + ping (marks connection as pinged)
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/ping-inactive/' . $this->appId, '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();

        // Receive the Pusher ping
        $msg = $this->recv($client, 2);
        $this->assertNotNull($msg);
        $data = json_decode($msg, associative: true);
        $this->assertSame('pusher:ping', $data['event']);

        // Do NOT respond with pong — connection is now pinged + inactive = stale

        // Step 2: prune stale connections (pinged but didn't respond)
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/prune-stale/' . $this->appId, '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();

        // Verify the connection receives a stale error and is disconnected
        $msg = $this->recv($client, 2);
        if ($msg !== null) {
            $data = json_decode($msg, associative: true);
            $this->assertSame('pusher:error', $data['event']);
            $this->assertStringContainsString('4201', $data['data']);
        }

        $client->close();
    }

    // ── Client events (whisper) ────────────────────────────────────────

    public function testCanHandleAClientWhisper()
    {
        ['client' => $sender, 'socketId' => $senderSocketId] = $this->connect();
        $this->subscribe($sender, $senderSocketId, 'private-whisper-channel');

        ['client' => $receiver, 'socketId' => $receiverSocketId] = $this->connect();
        $this->subscribe($receiver, $receiverSocketId, 'private-whisper-channel');

        $sender->push(json_encode([
            'event' => 'client-typing',
            'channel' => 'private-whisper-channel',
            'data' => ['user' => 'Joe'],
        ]));

        $msg = $this->recv($receiver);
        $this->assertNotNull($msg);

        $data = json_decode($msg, associative: true);
        $this->assertSame('client-typing', $data['event']);
        $this->assertSame('private-whisper-channel', $data['channel']);
        $this->assertSame(['user' => 'Joe'], $data['data']);

        // Sender should NOT receive their own whisper
        $senderMsg = $this->recv($sender, 0.5);
        $this->assertNull($senderMsg);

        $this->disconnect($sender);
        $this->disconnect($receiver);
    }

    // ── Multiple channels ──────────────────────────────────────────────

    public function testCanSubscribeToMultipleChannels()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $this->subscribe($client, $socketId, 'test-multi-1');
        $this->subscribe($client, $socketId, 'test-multi-2');
        $this->subscribe($client, $socketId, 'private-multi-3');
        $this->subscribe($client, $socketId, 'presence-multi-4', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        // Verify all channels are occupied via HTTP API
        $result = $this->signedServerRequest('channels');
        $body = json_decode($result['body'], associative: true);

        $this->assertArrayHasKey('test-multi-1', $body['channels']);
        $this->assertArrayHasKey('test-multi-2', $body['channels']);
        $this->assertArrayHasKey('private-multi-3', $body['channels']);
        $this->assertArrayHasKey('presence-multi-4', $body['channels']);

        $this->disconnect($client);
    }

    public function testCanSubscribeMultipleConnectionsToSameChannel()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'shared-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'shared-channel');

        // Verify subscription count via HTTP API
        $result = $this->signedServerRequest('channels/shared-channel?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(2, $body['subscription_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testCanSubscribeMultipleConnectionsToMultipleChannels()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'multi-test-channel');
        $this->subscribe($clientOne, $socketIdOne, 'multi-test-channel-2');
        $this->subscribe($clientOne, $socketIdOne, 'private-multi-test-channel-3');
        $this->subscribe($clientOne, $socketIdOne, 'presence-multi-test-channel-4', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User 1'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'multi-test-channel');
        $this->subscribe($clientTwo, $socketIdTwo, 'private-multi-test-channel-3');

        // Drain member_added on client one for the presence channel (client two didn't join it)
        // No drain needed — client two didn't subscribe to presence-multi-test-channel-4

        // Verify counts via HTTP API
        $result = $this->signedServerRequest('channels/multi-test-channel?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(2, $body['subscription_count']);

        $result = $this->signedServerRequest('channels/multi-test-channel-2?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(1, $body['subscription_count']);

        $result = $this->signedServerRequest('channels/private-multi-test-channel-3?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(2, $body['subscription_count']);

        $result = $this->signedServerRequest('channels/presence-multi-test-channel-4?info=user_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(1, $body['user_count']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── Channel removal ────────────────────────────────────────────────

    public function testRemovesChannelWhenNoSubscribersRemain()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-remove-channel');

        // Verify channel exists
        $result = $this->signedServerRequest('channels/test-remove-channel?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);

        // Unsubscribe
        $client->push(json_encode([
            'event' => 'pusher:unsubscribe',
            'data' => ['channel' => 'test-remove-channel'],
        ]));

        // Small delay for server to process
        usleep(100_000);

        // Verify channel is gone
        $result = $this->signedServerRequest('channels/test-remove-channel?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertFalse($body['occupied']);

        $this->disconnect($client);
    }

    // ── Message size limits ────────────────────────────────────────────

    public function testRejectsMessagesOverTheMaxAllowedSize()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        // max_message_size is 10000 in the test server config
        $client->push(json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'my-channel',
                'channel_data' => json_encode([str_repeat('a', 10_100)]),
            ],
        ]));

        $response = $this->recv($client, 2);
        $this->assertNotNull($response, 'Expected error response for oversized message');
        $this->assertStringContainsString('Maximum message size exceeded', $response);

        $this->disconnect($client);
    }

    public function testAllowsMessagesWithinTheMaxAllowedSize()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        // Send a message well within the 10000 byte limit
        $response = $this->send($client, [
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'size-ok-channel'],
        ]);

        $this->assertNotNull($response);
        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);

        $this->disconnect($client);
    }

    // ── Connection limits and origin validation ────────────────────────

    public function testCanHandleConnectionsToDifferentApplications()
    {
        // App 1 (default)
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect('reverb-key');
        $this->assertNotEmpty($socketIdOne);
        $this->disconnect($clientOne);

        // App 2
        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect('reverb-key-2');
        $this->assertNotEmpty($socketIdTwo);
        $this->disconnect($clientTwo);
    }

    public function testCannotConnectFromAnInvalidOrigin()
    {
        // App 3 (987654) only allows 'laravel.com' origins
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->setHeaders(['Origin' => 'http://not-allowed.com']);
        $client->upgrade('/app/reverb-key-3');

        $frame = $client->recv(3);
        $this->assertNotFalse($frame);

        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:error', $data['event']);
        $this->assertStringContainsString('4009', $data['data']);

        $client->close();
    }

    public function testCanConnectFromAValidOrigin()
    {
        // App 3 (987654) allows 'laravel.com' origins
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->setHeaders(['Origin' => 'http://laravel.com']);
        $client->upgrade('/app/reverb-key-3');

        $frame = $client->recv(3);
        $this->assertNotFalse($frame);

        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:connection_established', $data['event']);

        $client->close();
    }

    public function testCannotConnectWhenOverTheMaxConnectionLimit()
    {
        // App 2 (654321) has max_connections=1
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect('reverb-key-2');

        // Second connection should be rejected
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->upgrade('/app/reverb-key-2');

        $frame = $client->recv(3);
        $this->assertNotFalse($frame);

        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:error', $data['event']);

        $errorData = json_decode($data['data'], associative: true);
        $this->assertSame(4004, $errorData['code']);

        $client->close();
        $this->disconnect($clientOne);
    }

    // ── HTTP API ───────────────────────────────────────────────────────

    public function testHealthCheckEndpoint()
    {
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->get('/up');

        $this->assertSame(200, $client->getStatusCode());
        $this->assertSame('{"health":"OK"}', $client->getBody());

        $client->close();
    }

    public function testHttpApiReturns401WithoutSignature()
    {
        $client = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client->get('/apps/123456/channels');

        $this->assertSame(401, $client->getStatusCode());

        $client->close();
    }

    public function testHttpApiCanTriggerEvents()
    {
        ['client' => $wsClient, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($wsClient, $socketId, 'api-trigger-channel');

        $result = $this->signedServerPostRequest('events', [
            'name' => 'TestEvent',
            'channel' => 'api-trigger-channel',
            'data' => json_encode(['hello' => 'world']),
        ]);

        $this->assertSame(200, $result['status']);

        $msg = $this->recv($wsClient);
        $this->assertNotNull($msg);
        $data = json_decode($msg, associative: true);
        $this->assertSame('TestEvent', $data['event']);

        $this->disconnect($wsClient);
    }

    public function testHttpApiReturnsConnectionCount()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'conn-count-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'conn-count-channel');

        $result = $this->signedServerRequest('connections');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(2, $body['connections']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testHttpApiReturnsChannelUsers()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-users-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Alice'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-users-channel', [
            'user_id' => 2,
            'user_info' => ['name' => 'Bob'],
        ]);

        // Drain member_added on client one
        $this->recv($clientOne, 0.1);

        $result = $this->signedServerRequest('channels/presence-users-channel/users');
        $body = json_decode($result['body'], associative: true);
        $this->assertCount(2, $body['users']);

        $ids = array_column($body['users'], 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testHttpApiCanExcludeSocketId()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'exclude-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'exclude-channel');

        // Trigger event excluding client one
        $this->signedServerPostRequest('events', [
            'name' => 'TestEvent',
            'channel' => 'exclude-channel',
            'data' => json_encode(['test' => true]),
            'socket_id' => $socketIdOne,
        ]);

        // Client two should receive it
        $msgTwo = $this->recv($clientTwo);
        $this->assertNotNull($msgTwo);

        // Client one should NOT receive it
        $msgOne = $this->recv($clientOne, 0.5);
        $this->assertNull($msgOne);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── Content-Length headers ─────────────────────────────────────────

    public function testEventsEndpointSendsContentLengthHeader()
    {
        $result = $this->signedServerPostRequest('events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('2', $result['headers']['content-length']);
    }

    public function testBatchEventsEndpointSendsContentLengthHeader()
    {
        $result = $this->signedServerPostRequest('batch_events', ['batch' => [
            ['name' => 'NewEvent', 'channel' => 'test-channel', 'data' => json_encode(['some' => 'data'])],
        ]]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('12', $result['headers']['content-length']);
    }

    public function testChannelsEndpointSendsContentLengthHeader()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-cl-channel');

        $result = $this->signedServerRequest('channels?info=user_count');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }

    public function testChannelUsersEndpointSendsContentLengthHeader()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-cl-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Taylor'],
        ]);

        $result = $this->signedServerRequest('channels/presence-cl-channel/users');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }

    public function testConnectionsEndpointSendsContentLengthHeader()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'test-conn-cl-channel');

        $result = $this->signedServerRequest('connections');

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('content-length', $result['headers']);
        $this->assertSame((string) strlen($result['body']), $result['headers']['content-length']);

        $this->disconnect($client);
    }

    // ── Webhooks ──────────────────────────────────────────────────────

    public function testWebhookDispatchedForChannelOccupied()
    {
        $this->resetQueueFake();

        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'webhook-occupy-channel');

        // Small delay for server to process the webhook dispatch
        usleep(100_000);

        $jobs = $this->getQueuedWebhookJobs();

        $this->assertNotEmpty($jobs, 'Expected channel_occupied webhook');
        $occupiedJob = collect($jobs)->firstWhere('event', 'channel_occupied');
        $this->assertNotNull($occupiedJob);
        $this->assertSame('webhook-occupy-channel', $occupiedJob['channel']);
        $this->assertSame($this->appKey, $occupiedJob['appKey']);

        $this->disconnect($client);
    }

    public function testWebhookDispatchedForChannelVacated()
    {
        $this->resetQueueFake();

        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'webhook-vacate-channel');

        // Unsubscribe to vacate the channel
        $client->push(json_encode([
            'event' => 'pusher:unsubscribe',
            'data' => ['channel' => 'webhook-vacate-channel'],
        ]));
        usleep(100_000);

        $jobs = $this->getQueuedWebhookJobs();

        $vacatedJob = collect($jobs)->firstWhere('event', 'channel_vacated');
        $this->assertNotNull($vacatedJob, 'Expected channel_vacated webhook');
        $this->assertSame('webhook-vacate-channel', $vacatedJob['channel']);

        $this->disconnect($client);
    }

    public function testWebhookDispatchedForMemberAdded()
    {
        $this->resetQueueFake();

        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-webhook-member-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'User 1'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-webhook-member-channel', [
            'user_id' => 2,
            'user_info' => ['name' => 'User 2'],
        ]);

        usleep(100_000);

        $jobs = $this->getQueuedWebhookJobs();

        $memberAddedJobs = collect($jobs)->where('event', 'member_added')->values();
        $this->assertGreaterThanOrEqual(1, $memberAddedJobs->count(), 'Expected member_added webhook');

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testWebhookDispatchedForMemberRemoved()
    {
        $this->resetQueueFake();

        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-webhook-remove-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'User 1'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-webhook-remove-channel', [
            'user_id' => 2,
            'user_info' => ['name' => 'User 2'],
        ]);

        // Drain member_added on client one
        $this->recv($clientOne, 0.1);

        // Disconnect client two to trigger member_removed
        $this->disconnect($clientTwo);
        usleep(200_000);

        $jobs = $this->getQueuedWebhookJobs();

        $memberRemovedJob = collect($jobs)->firstWhere('event', 'member_removed');
        $this->assertNotNull($memberRemovedJob, 'Expected member_removed webhook');
        $this->assertSame('presence-webhook-remove-channel', $memberRemovedJob['channel']);

        $this->disconnect($clientOne);
    }

    public function testWebhookDispatchedForClientEvent()
    {
        $this->resetQueueFake();

        ['client' => $sender, 'socketId' => $senderSocketId] = $this->connect();
        $this->subscribe($sender, $senderSocketId, 'private-webhook-client-channel');

        ['client' => $receiver, 'socketId' => $receiverSocketId] = $this->connect();
        $this->subscribe($receiver, $receiverSocketId, 'private-webhook-client-channel');

        // Send a client event
        $sender->push(json_encode([
            'event' => 'client-typing',
            'channel' => 'private-webhook-client-channel',
            'data' => ['user' => 'Joe'],
        ]));
        usleep(100_000);

        $jobs = $this->getQueuedWebhookJobs();

        $clientEventJob = collect($jobs)->firstWhere('event', 'client_event');
        $this->assertNotNull($clientEventJob, 'Expected client_event webhook');
        $this->assertSame('private-webhook-client-channel', $clientEventJob['channel']);

        $this->disconnect($sender);
        $this->disconnect($receiver);
    }

    public function testWebhookIncludesIdempotencyKey()
    {
        $this->resetQueueFake();

        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'webhook-idempotency-channel');

        usleep(100_000);

        $jobs = $this->getQueuedWebhookJobs();

        $this->assertNotEmpty($jobs, 'Expected at least one webhook');
        $occupiedJob = collect($jobs)->firstWhere('event', 'channel_occupied');
        $this->assertNotNull($occupiedJob);
        $this->assertNotEmpty($occupiedJob['webhookId']);
        $this->assertSame(36, strlen($occupiedJob['webhookId']));

        $this->disconnect($client);
    }

    public function testDrainCleansUpSharedStateCounters()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'drain-counter-test');

        // Subscription counter should be 1
        $count = $this->readSharedState("sub:{$this->appId}:drain-counter-test");
        $this->assertSame(1, $count);

        // Drain all connections
        $this->triggerDrain();
        usleep(100_000);

        // Counter should be gone (key deleted when count reaches 0)
        $count = $this->readSharedState("sub:{$this->appId}:drain-counter-test");
        $this->assertNull($count);
    }

    public function testDrainReleasesConnectionSlots()
    {
        // reverb-key-2 app has max_connections=1
        ['client' => $client] = $this->connect('reverb-key-2');

        // Drain — should release the slot
        $this->triggerDrain();
        usleep(100_000);

        // Should be able to connect again (slot released by drain)
        ['client' => $newClient] = $this->connect('reverb-key-2');

        $this->disconnect($newClient);
    }

    /**
     * Trigger the drain endpoint on the test server.
     */
    protected function triggerDrain(): void
    {
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/drain-connections', '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();
    }

    /**
     * Read a value from the Swoole Table shared state via the test endpoint.
     */
    protected function readSharedState(string $key): ?int
    {
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->get('/_test/shared-state/' . urlencode($key));

        $body = json_decode($httpClient->body, associative: true);
        $httpClient->close();

        return $body['count'] ?? null;
    }
}
