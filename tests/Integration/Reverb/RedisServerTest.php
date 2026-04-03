<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * End-to-end integration tests for Reverb with Redis scaling enabled.
 *
 * Requires a running Redis-enabled test server:
 *   REVERB_SERVER_PORT=19511 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 * Tests auto-skip when Redis or the server is unavailable.
 *
 * @internal
 * @coversNothing
 */
class RedisServerTest extends ReverbRedisIntegrationTestCase
{
    protected int $serverPort = 19511;

    // ── Broadcast via Redis pub/sub ────────────────────────────────────

    public function testCanPublishAndSubscribeToATriggeredEventViaRedis()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'presence-redis-broadcast-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        $this->triggerEvent(
            'presence-redis-broadcast-channel',
            'App\Events\TestEvent',
            ['foo' => 'bar'],
        );

        $msg = $this->recv($client);
        $this->assertNotNull($msg, 'Expected broadcast via Redis pub/sub');

        $data = json_decode($msg, associative: true);
        $this->assertSame('App\Events\TestEvent', $data['event']);
        $this->assertSame('presence-redis-broadcast-channel', $data['channel']);

        $this->disconnect($client);
    }

    public function testCanPublishAndSubscribeToAClientWhisperViaRedis()
    {
        ['client' => $sender, 'socketId' => $senderSocketId] = $this->connect();
        $this->subscribe($sender, $senderSocketId, 'private-redis-whisper-channel');

        ['client' => $receiver, 'socketId' => $receiverSocketId] = $this->connect();
        $this->subscribe($receiver, $receiverSocketId, 'private-redis-whisper-channel');

        $sender->push(json_encode([
            'event' => 'client-typing',
            'channel' => 'private-redis-whisper-channel',
            'data' => ['user' => 'Joe'],
        ]));

        $msg = $this->recv($receiver);
        $this->assertNotNull($msg, 'Expected whisper via Redis pub/sub');

        $data = json_decode($msg, associative: true);
        $this->assertSame('client-typing', $data['event']);
        $this->assertSame('private-redis-whisper-channel', $data['channel']);

        // Sender should NOT receive their own whisper
        $senderMsg = $this->recv($sender, 0.5);
        $this->assertNull($senderMsg);

        $this->disconnect($sender);
        $this->disconnect($receiver);
    }

    // ── Terminate via Redis pub/sub ────────────────────────────────────

    public function testTerminatesUserAcrossServersViaRedis()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-redis-terminate-channel', [
            'user_id' => '789',
            'user_info' => ['name' => 'User 789'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-redis-terminate-channel', [
            'user_id' => '987',
            'user_info' => ['name' => 'User 987'],
        ]);

        // Drain member_added on client one
        $this->recv($clientOne, 0.1);

        // Terminate user 987 via HTTP API
        $result = $this->signedServerPostRequest('users/987/terminate_connections');
        $this->assertSame(200, $result['status']);
        $this->assertSame('{}', $result['body']);

        // Verify client one still connected (can receive).
        // Drain any member_removed notification first — it may arrive
        // before the triggered event depending on processing order.
        $this->triggerEvent(
            'presence-redis-terminate-channel',
            'StillAlive',
            ['check' => true],
        );

        $messages = $this->recvAll($clientOne, 2);
        $found = false;
        foreach ($messages as $msg) {
            $decoded = json_decode($msg, associative: true);
            if (($decoded['event'] ?? '') === 'StillAlive') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Client one should still be connected and receive events');

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    // ── Basic connectivity with Redis scaling ──────────────────────────

    public function testCanConnectAndSubscribeWithRedisScaling()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'redis-basic-channel');

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);

        // Verify via HTTP API
        $result = $this->signedServerRequest('channels/redis-basic-channel?info=subscription_count');
        $body = json_decode($result['body'], associative: true);
        $this->assertTrue($body['occupied']);
        $this->assertSame(1, $body['subscription_count']);

        $this->disconnect($client);
    }

    public function testCanSubscribeToPresenceChannelWithRedisScaling()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();

        $response = $this->subscribe($client, $socketId, 'presence-redis-presence-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'Test User'],
        ]);

        $data = json_decode($response, associative: true);
        $this->assertSame('pusher_internal:subscription_succeeded', $data['event']);

        // Verify user count via HTTP API
        $result = $this->signedServerRequest('channels/presence-redis-presence-channel/users');
        $body = json_decode($result['body'], associative: true);
        $this->assertCount(1, $body['users']);
        $this->assertSame(1, $body['users'][0]['id']);

        $this->disconnect($client);
    }

    public function testPresenceMemberNotificationsWithRedisScaling()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'presence-redis-notify-channel', [
            'user_id' => 1,
            'user_info' => ['name' => 'User 1'],
        ]);

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'presence-redis-notify-channel', [
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

    // ── HTTP API with Redis scaling ────────────────────────────────────

    public function testHttpApiConnectionCountWithRedisScaling()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'redis-conn-count-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'redis-conn-count-channel');

        $result = $this->signedServerRequest('connections');
        $body = json_decode($result['body'], associative: true);
        $this->assertSame(2, $body['connections']);

        $this->disconnect($clientOne);
        $this->disconnect($clientTwo);
    }

    public function testHttpApiChannelsListWithRedisScaling()
    {
        ['client' => $client, 'socketId' => $socketId] = $this->connect();
        $this->subscribe($client, $socketId, 'redis-list-channel-one');
        $this->subscribe($client, $socketId, 'redis-list-channel-two');

        $result = $this->signedServerRequest('channels');
        $body = json_decode($result['body'], associative: true);

        $this->assertArrayHasKey('redis-list-channel-one', $body['channels']);
        $this->assertArrayHasKey('redis-list-channel-two', $body['channels']);

        $this->disconnect($client);
    }

    public function testHttpApiSocketIdExclusionWithRedisScaling()
    {
        ['client' => $clientOne, 'socketId' => $socketIdOne] = $this->connect();
        $this->subscribe($clientOne, $socketIdOne, 'redis-exclude-channel');

        ['client' => $clientTwo, 'socketId' => $socketIdTwo] = $this->connect();
        $this->subscribe($clientTwo, $socketIdTwo, 'redis-exclude-channel');

        $this->signedServerPostRequest('events', [
            'name' => 'TestEvent',
            'channel' => 'redis-exclude-channel',
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
}
