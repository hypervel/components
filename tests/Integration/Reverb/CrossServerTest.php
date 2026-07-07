<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * Cross-server integration tests for Reverb with Redis scaling.
 *
 * Proves Redis pub/sub delivers events between two separate Reverb instances
 * and RedisSharedState coordinates global state across servers.
 *
 * Requires two running Redis-enabled servers:
 *   REVERB_SERVER_PORT=19513 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 *   REVERB_SERVER_PORT=19514 REVERB_SCALING_ENABLED=true php tests/Integration/Reverb/server.php
 */
class CrossServerTest extends CrossServerTestCase
{
    public function testBroadcastFromServerBReachesSubscriberOnServerA()
    {
        // Client subscribes on server A
        ['client' => $clientA, 'socketId' => $socketIdA] = $this->connectToServerA();
        $this->subscribeOnServerA($clientA, $socketIdA, 'cross-server-broadcast');

        // Trigger event via HTTP API on server B
        $this->signedPostToServerB('events', [
            'name' => 'App\Events\CrossServerEvent',
            'channel' => 'cross-server-broadcast',
            'data' => json_encode(['from' => 'server-b']),
        ]);

        // Client on server A should receive it via Redis pub/sub
        $message = $this->receiveEvent($clientA, 'App\Events\CrossServerEvent');
        $this->assertNotNull($message, 'Client on server A did not receive broadcast from server B');
        $this->assertSame('cross-server-broadcast', $message['channel']);

        $this->disconnect($clientA);
    }

    public function testConnectionLimitIsGlobalAcrossServers()
    {
        // reverb-key-2 app has max_connections=1
        // Connect on server A — should succeed
        ['client' => $clientA] = $this->connectToServerA('reverb-key-2');

        // Connect on server B with same app — should be rejected (global limit)
        $clientB = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->serverBPort);
        $clientB->upgrade('/app/reverb-key-2');
        $frame = $clientB->recv(3);

        $this->assertInstanceOf(\Swoole\WebSocket\Frame::class, $frame);
        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:error', $data['event']);

        $errorData = json_decode($data['data'], associative: true);
        $this->assertSame(4004, $errorData['code']);

        $this->disconnect($clientA);
        $clientB->close();
    }

    public function testPresenceMemberAddedAcrossServers()
    {
        // Observer on server A
        ['client' => $observer, 'socketId' => $observerSocketId] = $this->connectToServerA();
        $this->subscribeOnServerA($observer, $observerSocketId, 'presence-cross-server-member', [
            'user_id' => 'observer',
            'user_info' => ['name' => 'Observer'],
        ]);

        // New user joins on server B
        ['client' => $clientB, 'socketId' => $socketIdB] = $this->connectToServerB();
        $this->subscribeOnServerB($clientB, $socketIdB, 'presence-cross-server-member', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2'],
        ]);

        // Observer on server A should receive member_added via Redis pub/sub
        $message = $this->receiveMemberAdded($observer, 'user-2');
        $this->assertNotNull($message, 'Observer on server A did not receive member_added from server B');

        // Third client with SAME user_id joins on server A — no duplicate member_added
        ['client' => $clientA2, 'socketId' => $socketIdA2] = $this->connectToServerA();
        $this->subscribeOnServerA($clientA2, $socketIdA2, 'presence-cross-server-member', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2 again'],
        ]);

        $messages = $this->receiveMemberAddedEvents($observer, 'user-2');
        $this->assertCount(0, $messages, 'Observer received duplicate member_added for same user across servers');

        $this->disconnect($observer);
        $this->disconnect($clientB);
        $this->disconnect($clientA2);
    }

    public function testWhisperFromServerAReachesServerB()
    {
        // Client A on server A
        ['client' => $clientA, 'socketId' => $socketIdA] = $this->connectToServerA();
        $this->subscribeOnServerA($clientA, $socketIdA, 'private-cross-server-whisper');

        // Client B on server B
        ['client' => $clientB, 'socketId' => $socketIdB] = $this->connectToServerB();
        $this->subscribeOnServerB($clientB, $socketIdB, 'private-cross-server-whisper');

        // Client A sends a whisper (client event)
        $clientA->push(json_encode([
            'event' => 'client-typing',
            'channel' => 'private-cross-server-whisper',
            'data' => ['user' => 'taylor'],
        ]));

        // Client B should receive it via Redis pub/sub
        $message = $this->receiveEvent($clientB, 'client-typing');
        $this->assertNotNull($message, 'Client B on server B did not receive whisper from server A');
        $this->assertSame('private-cross-server-whisper', $message['channel']);

        $this->disconnect($clientA);
        $this->disconnect($clientB);
    }

    public function testPresenceMemberRemovedAcrossServers()
    {
        // Observer on server A
        ['client' => $observer, 'socketId' => $observerSocketId] = $this->connectToServerA();
        $this->subscribeOnServerA($observer, $observerSocketId, 'presence-cross-server-remove', [
            'user_id' => 'observer',
            'user_info' => ['name' => 'Observer'],
        ]);

        // User joins on server B
        ['client' => $clientB, 'socketId' => $socketIdB] = $this->connectToServerB();
        $this->subscribeOnServerB($clientB, $socketIdB, 'presence-cross-server-remove', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2'],
        ]);

        // Drain the member_added on the observer
        $message = $this->receiveMemberAdded($observer, 'user-2');
        $this->assertNotNull($message, 'Observer did not receive member_added');

        // Disconnect the user on server B — observer on server A should receive member_removed
        $this->disconnect($clientB);

        $message = $this->receiveMemberRemoved($observer, 'user-2');
        $this->assertNotNull($message, 'Observer on server A did not receive member_removed from server B');

        $this->disconnect($observer);
    }
}
