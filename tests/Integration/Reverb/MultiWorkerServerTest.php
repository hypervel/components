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
    public function testBroadcastReachesClientsOnDifferentWorkers()
    {
        $result = $this->connectOnDifferentWorkers('test-broadcast-mw');

        // Trigger event via HTTP API — may land on either worker
        $this->triggerEvent('test-broadcast-mw', 'App\Events\TestEvent', ['message' => 'hello']);

        // ALL clients should receive the broadcast — including those on
        // a different worker from the one that handled the HTTP trigger.
        // This proves ChannelBroadcastPipeMessage fan-out is working.
        foreach ($result['connections'] as $conn) {
            $data = $this->recv($conn['client'], 2);
            $this->assertNotNull($data, 'Client on worker ' . $conn['workerId'] . ' did not receive broadcast');

            $decoded = json_decode($data, associative: true);
            $this->assertSame('App\Events\TestEvent', $decoded['event']);
        }

        foreach ($result['connections'] as $conn) {
            $this->disconnect($conn['client']);
        }
    }

    public function testConnectionLimitIsGlobalAcrossWorkers()
    {
        // reverb-key-2 app has max_connections=1
        $conn = $this->connect('reverb-key-2');

        // Counter should be 1 globally (shared memory)
        $count = $this->readSharedState('conn:654321');
        $this->assertSame(1, $count);

        // Second connection should be rejected (global limit reached)
        $client2 = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $client2->upgrade('/app/reverb-key-2');
        $frame = $client2->recv(3);

        $this->assertInstanceOf(\Swoole\WebSocket\Frame::class, $frame);
        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:error', $data['event']);

        $errorData = json_decode($data['data'], associative: true);
        $this->assertSame(4004, $errorData['code']);

        // Counter should still be 1 (rejected connection didn't leak a slot)
        $count = $this->readSharedState('conn:654321');
        $this->assertSame(1, $count);

        $this->disconnect($conn['client']);
        $client2->close();
    }

    public function testPresenceMemberAddedFiresOnceGlobally()
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
        $msg = $this->recv($observer['client'], 3);
        $this->assertNotNull($msg, 'Observer did not receive member_added for user-2');
        $decoded = json_decode($msg, associative: true);
        $this->assertSame('pusher_internal:member_added', $decoded['event']);

        // Third client with SAME user_id=2 — observer should NOT get another member_added
        $duplicate = $this->connect();
        $this->subscribe($duplicate['client'], $duplicate['socketId'], 'presence-mw-member-test', [
            'user_id' => 'user-2',
            'user_info' => ['name' => 'User 2 again'],
        ]);

        $msg = $this->recv($observer['client'], 1);
        $this->assertNull($msg, 'Observer received duplicate member_added for same user');

        $this->disconnect($observer['client']);
        $this->disconnect($joiner['client']);
        $this->disconnect($duplicate['client']);
    }

    public function testPresenceMemberRemovedFiresOnceGlobally()
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

        // Drain the member_added from observer
        $this->recv($observer['client'], 1);

        // Disconnect first client — member_removed should NOT fire (user still connected)
        $this->disconnect($clientA['client']);
        usleep(200_000);

        $msg = $this->recv($observer['client'], 1);
        $this->assertNull($msg, 'Observer received premature member_removed');

        // Disconnect second client — NOW member_removed fires
        $this->disconnect($clientB['client']);
        usleep(200_000);

        $msg = $this->recv($observer['client'], 2);
        $this->assertNotNull($msg, 'Observer did not receive member_removed');
        $decoded = json_decode($msg, associative: true);
        $this->assertSame('pusher_internal:member_removed', $decoded['event']);

        $this->disconnect($observer['client']);
    }

    public function testSubscriptionCountIsGlobalAcrossWorkers()
    {
        $result = $this->connectOnDifferentWorkers('test-sub-count-mw');
        $totalClients = count($result['connections']);

        // Shared state counter should match total subscribers across all workers
        $count = $this->readSharedState("sub:{$this->appId}:test-sub-count-mw");
        $this->assertSame($totalClients, $count);

        // Disconnect one client
        $first = array_shift($result['connections']);
        $this->disconnect($first['client']);
        usleep(200_000);

        $count = $this->readSharedState("sub:{$this->appId}:test-sub-count-mw");
        $this->assertSame($totalClients - 1, $count);

        // Disconnect all remaining
        foreach ($result['connections'] as $conn) {
            $this->disconnect($conn['client']);
        }
        usleep(200_000);

        // Counter should be gone (key deleted when count reaches 0)
        $count = $this->readSharedState("sub:{$this->appId}:test-sub-count-mw");
        $this->assertNull($count);
    }

    public function testDrainOnOneWorkerDoesNotAffectOtherWorker()
    {
        // Connect clients on different workers, subscribe to same channel
        $result = $this->connectOnDifferentWorkers('test-drain-mw');

        // Trigger drain — the HTTP request hits one worker, draining only
        // the connections on that worker
        $httpClient = new \Swoole\Coroutine\Http\Client($this->getServerHost(), $this->getServerPort());
        $httpClient->post('/_test/drain-connections', '');
        $this->assertSame(200, $httpClient->getStatusCode());
        $httpClient->close();

        usleep(200_000);

        // At least one client should still be connected (on the other worker).
        // Trigger a broadcast and check who receives it.
        $this->triggerEvent('test-drain-mw', 'App\Events\DrainTest', ['after' => 'drain']);

        $receivedCount = 0;
        foreach ($result['connections'] as $conn) {
            $data = $this->recv($conn['client'], 1);

            if ($data !== null) {
                $decoded = json_decode($data, associative: true);

                if (($decoded['event'] ?? null) === 'App\Events\DrainTest') {
                    ++$receivedCount;
                }
            }
        }

        // At least one client should have received the broadcast (the one on the non-drained worker)
        $this->assertGreaterThanOrEqual(1, $receivedCount, 'No clients received broadcast after drain — drain may have affected other workers');

        // But not all clients should have received it (the drained ones are gone)
        $this->assertLessThan(count($result['connections']), $receivedCount, 'All clients received broadcast — drain may not have worked');

        foreach ($result['connections'] as $conn) {
            @$conn['client']->close();
        }
    }
}
