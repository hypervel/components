<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * Multi-worker integration tests for Reverb (non-scaling mode).
 *
 * Exercises pipe message fan-out, Swoole Table cross-worker atomicity,
 * and presence semantics across workers.
 *
 * Requires: REVERB_SERVER_PORT=19512 REVERB_TEST_WORKER_NUM=2 php tests/Integration/Reverb/server.php
 */
class MultiWorkerServerTest extends MultiWorkerTestCase
{
    public function testBroadcastReachesClientsOnDifferentWorkers(): void
    {
        $result = $this->connectOnDifferentWorkers('test-broadcast-mw');

        // Trigger event via HTTP API — may land on either worker
        $this->triggerEvent('test-broadcast-mw', 'App\Events\TestEvent', ['message' => 'hello']);

        // ALL clients should receive the broadcast — including those on
        // a different worker from the one that handled the HTTP trigger.
        // This proves ChannelBroadcastPipeMessage fan-out is working.
        foreach ($result['connections'] as $connection) {
            $message = $this->receiveEvent($connection['client'], 'App\Events\TestEvent');
            $this->assertNotNull($message, 'Client on worker ' . $connection['workerId'] . ' did not receive broadcast');
        }

        foreach ($result['connections'] as $connection) {
            $this->disconnect($connection['client']);
        }
    }

    public function testConnectionLimitIsGlobalAcrossWorkers(): void
    {
        // reverb-key-2 app has max_connections=1
        $conn = $this->connect('reverb-key-2');

        // Second connection should be rejected (global limit reached)
        $client2 = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client2->upgrade('/app/reverb-key-2');
        $frame = $client2->recv(3);

        $this->assertInstanceOf(\Swoole\WebSocket\Frame::class, $frame);
        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:error', $data['event']);

        $errorData = json_decode($data['data'], associative: true);
        $this->assertSame(4004, $errorData['code']);

        $this->disconnect($conn['client']);
        $client2->close();
    }

    public function testPresenceMemberAddedFiresOnceGlobally(): void
    {
        // Connect observer, then keep connecting until we get a joiner on a different worker
        $observer = $this->connect();
        $this->subscribe($observer['client'], $observer['socketId'], 'presence-mw-member-test', [
            'user_id' => 'observer',
            'user_info' => ['name' => 'Observer'],
        ]);

        $joiner = $this->connectOnDifferentWorkerThan($observer['workerId']);
        $this->subscribe($joiner['client'], $joiner['socketId'], 'presence-mw-member-test', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2'],
        ]);

        // Observer should receive member_added via pipe fan-out (cross-worker)
        $message = $this->receiveMemberAdded($observer['client'], 'user-2');
        $this->assertNotNull($message, 'Observer did not receive member_added for user-2');

        // Third client with SAME user_id=2 — observer should NOT get another member_added
        $duplicate = $this->connect();
        $this->subscribe($duplicate['client'], $duplicate['socketId'], 'presence-mw-member-test', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2 again'],
        ]);

        $messages = $this->receiveMemberAddedEvents($observer['client'], 'user-2');
        $this->assertCount(0, $messages, 'Observer received duplicate member_added for same user');

        $this->disconnect($observer['client']);
        $this->disconnect($joiner['client']);
        $this->disconnect($duplicate['client']);
    }

    public function testPresenceSubscriptionIncludesMembersFromSiblingWorkers(): void
    {
        $observer = $this->connect();
        $this->subscribe($observer['client'], $observer['socketId'], 'presence-mw-roster', [
            'user_id' => 'observer',
            'user_info' => ['name' => 'Observer'],
        ]);

        $joiner = $this->connectOnDifferentWorkerThan($observer['workerId']);
        $response = $this->subscribe($joiner['client'], $joiner['socketId'], 'presence-mw-roster', [
            'user_id' => 'joiner',
            'user_info' => ['name' => 'Joiner'],
        ]);

        $event = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);
        $data = json_decode($event['data'], associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $data['presence']['count']);
        $this->assertEqualsCanonicalizing(['observer', 'joiner'], $data['presence']['ids']);
        $this->assertSame(['name' => 'Observer'], $data['presence']['hash']['observer']);
        $this->assertSame(['name' => 'Joiner'], $data['presence']['hash']['joiner']);

        $this->disconnect($observer['client']);
        $this->disconnect($joiner['client']);
    }

    public function testPresenceMemberRemovedFiresOnceGlobally(): void
    {
        // Observer on one worker
        $observer = $this->connect();
        $this->subscribe($observer['client'], $observer['socketId'], 'presence-mw-remove-test', [
            'user_id' => 'observer',
            'user_info' => ['name' => 'Observer'],
        ]);

        // Two clients with same user_id — at least one on a different worker than observer
        $clientA = $this->connectOnDifferentWorkerThan($observer['workerId']);
        $this->subscribe($clientA['client'], $clientA['socketId'], 'presence-mw-remove-test', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2'],
        ]);

        $clientB = $this->connect();
        $this->subscribe($clientB['client'], $clientB['socketId'], 'presence-mw-remove-test', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2 again'],
        ]);

        // Drain the member_added from observer.
        $message = $this->receiveMemberAdded($observer['client'], 'user-2');
        $this->assertNotNull($message, 'Observer did not receive member_added for user-2');

        // Disconnect first client — member_removed should NOT fire (user still connected)
        $this->disconnect($clientA['client']);

        $messages = $this->receiveMemberRemovedEvents($observer['client'], 'user-2');
        $this->assertCount(0, $messages, 'Observer received premature member_removed');

        // Disconnect second client — NOW member_removed fires
        $this->disconnect($clientB['client']);

        $message = $this->receiveMemberRemoved($observer['client'], 'user-2');
        $this->assertNotNull($message, 'Observer did not receive member_removed');

        $this->disconnect($observer['client']);
    }

    public function testSubscriptionCountIsGlobalAcrossWorkers(): void
    {
        $result = $this->connectOnDifferentWorkers('test-sub-count-mw');
        $totalClients = count($result['connections']);

        // Shared state counter should match total subscribers across all workers
        $count = $this->readSubscriptionCount($this->appId, 'test-sub-count-mw');
        $this->assertSame($totalClients, $count);

        // Disconnect one client
        $first = array_shift($result['connections']);
        $this->disconnect($first['client']);

        $count = $this->waitForSubscriptionCount(
            $this->appId,
            'test-sub-count-mw',
            $totalClients - 1,
        );
        $this->assertSame($totalClients - 1, $count);

        // Disconnect all remaining
        foreach ($result['connections'] as $connection) {
            $this->disconnect($connection['client']);
        }

        $count = $this->waitForSubscriptionCount($this->appId, 'test-sub-count-mw', 0);
        $this->assertSame(0, $count);
    }

    public function testHttpMetricsIncludeConnectionsAndPresenceUsersFromSiblingWorkers(): void
    {
        $first = $this->connect();
        $this->subscribe($first['client'], $first['socketId'], 'presence-mw-metrics', [
            'user_id' => 'first',
            'user_info' => ['name' => 'First'],
        ]);

        $second = $this->connectOnDifferentWorkerThan($first['workerId']);
        $this->subscribe($second['client'], $second['socketId'], 'presence-mw-metrics', [
            'user_id' => 'second',
            'user_info' => ['name' => 'Second'],
        ]);

        $connections = $this->signedServerRequest('connections');
        $this->assertSame(200, $connections['status']);
        $this->assertSame(2, json_decode(
            $connections['body'],
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        )['connections']);

        $channel = $this->signedServerRequest('channels/presence-mw-metrics?info=user_count');
        $this->assertSame(200, $channel['status']);
        $this->assertSame(2, json_decode(
            $channel['body'],
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        )['user_count']);

        $users = $this->signedServerRequest('channels/presence-mw-metrics/users');
        $this->assertSame(200, $users['status']);
        $userIds = array_column(json_decode(
            $users['body'],
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        )['users'], 'id');
        $this->assertEqualsCanonicalizing(['first', 'second'], $userIds);

        $this->disconnect($first['client']);
        $this->disconnect($second['client']);
    }

    public function testUserTerminationDisconnectsMatchingClientsOnEveryWorker(): void
    {
        $first = $this->connect();
        $this->subscribe($first['client'], $first['socketId'], 'presence-mw-termination', [
            'user_id' => 'target',
            'user_info' => ['name' => 'Target'],
        ]);

        $second = $this->connectOnDifferentWorkerThan($first['workerId']);
        $this->subscribe($second['client'], $second['socketId'], 'presence-mw-termination', [
            'user_id' => 'target',
            'user_info' => ['name' => 'Target'],
        ]);

        $response = $this->signedServerPostRequest('users/target/terminate_connections');

        $this->assertSame(200, $response['status']);

        $first['client']->recv(2);
        $second['client']->recv(2);

        $this->assertFalse($first['client']->connected);
        $this->assertFalse($second['client']->connected);
    }

    public function testDrainOnOneWorkerDoesNotAffectOtherWorker(): void
    {
        // Connect clients on different workers, subscribe to same channel
        $result = $this->connectOnDifferentWorkers('test-drain-mw');

        // Trigger drain — the HTTP request hits one worker, draining only
        // the connections on that worker
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/drain-connections', '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();

        // At least one client should still be connected (on the other worker).
        // Trigger a broadcast and check who receives it.
        $this->triggerEvent('test-drain-mw', 'App\Events\DrainTest', ['after' => 'drain']);

        $receivedCount = 0;
        foreach ($result['connections'] as $connection) {
            $message = $this->receiveEvent($connection['client'], 'App\Events\DrainTest', 1);

            if ($message !== null) {
                ++$receivedCount;
            }
        }

        // At least one client should have received the broadcast (the one on the non-drained worker)
        $this->assertGreaterThanOrEqual(1, $receivedCount, 'No clients received broadcast after drain — drain may have affected other workers');

        // But not all clients should have received it (the drained ones are gone)
        $this->assertLessThan(count($result['connections']), $receivedCount, 'All clients received broadcast — drain may not have worked');

        foreach ($result['connections'] as $connection) {
            @$connection['client']->close();
        }
    }
}
