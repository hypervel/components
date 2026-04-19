<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

/**
 * Base test case for multi-worker Reverb integration tests.
 *
 * Connects to port 19512 (SWOOLE_PROCESS, worker_num=2, no scaling).
 * Provides worker-aware connect helpers for deterministic cross-worker testing.
 *
 * Start it with: REVERB_SERVER_PORT=19512 REVERB_TEST_WORKER_NUM=2 php tests/Integration/Reverb/server.php
 */
abstract class MultiWorkerTestCase extends ReverbIntegrationTestCase
{
    protected int $serverPort = 19512;

    /**
     * Connect and retrieve the worker ID that owns the connection.
     *
     * Sends a test-only pusher:test_worker_id event after connecting.
     * The test server responds with the owning worker's ID.
     *
     * @return array{client: Client, socketId: string, workerId: int}
     */
    protected function connect(?string $appKey = null): array
    {
        $result = parent::connect($appKey);

        $result['client']->push(json_encode(['event' => 'pusher:test_worker_id']));

        $frame = $result['client']->recv(3);
        $this->assertInstanceOf(Frame::class, $frame, 'Did not receive test_worker_id response');

        $data = json_decode($frame->data, associative: true);
        $this->assertSame('pusher:test_worker_id', $data['event']);

        $workerData = json_decode($data['data'], associative: true);
        $result['workerId'] = (int) $workerData['worker_id'];

        return $result;
    }

    /**
     * Connect clients until at least two different workers are represented.
     *
     * Subscribes each client to the given channel. Returns all connections
     * grouped by worker ID, or skips the test if distribution cannot be
     * achieved within 20 attempts.
     *
     * @return array{connections: list<array{client: Client, socketId: string, workerId: int}>, workers: list<int>}
     */
    protected function connectOnDifferentWorkers(string $channel, ?array $userData = null): array
    {
        $connections = [];
        $workersSeen = [];

        for ($i = 0; $i < 20; ++$i) {
            $conn = $this->connect();
            $this->subscribe($conn['client'], $conn['socketId'], $channel, $userData);
            $connections[] = $conn;
            $workersSeen[$conn['workerId']] = true;

            if (count($workersSeen) >= 2) {
                return ['connections' => $connections, 'workers' => array_keys($workersSeen)];
            }
        }

        $this->fail('Could not distribute connections across multiple workers after 20 attempts');
    }

    /**
     * Connect a client that lands on a different worker than the given one.
     *
     * Keeps connecting until a connection on a different worker is found.
     * Fails the test if distribution cannot be achieved within 20 attempts.
     *
     * @return array{client: Client, socketId: string, workerId: int}
     */
    protected function connectOnDifferentWorkerThan(int $avoidWorkerId): array
    {
        for ($i = 0; $i < 20; ++$i) {
            $conn = $this->connect();

            if ($conn['workerId'] !== $avoidWorkerId) {
                return $conn;
            }

            // Wrong worker — disconnect and try again
            $this->disconnect($conn['client']);
        }

        $this->fail('Could not connect to a different worker after 20 attempts');
    }

    /**
     * Read a value from the Swoole Table shared state via the test endpoint.
     */
    protected function readSharedState(string $key): ?int
    {
        $httpClient = new Client($this->getServerHost(), $this->getServerPort());
        $httpClient->get('/_test/shared-state/' . urlencode($key));

        $body = json_decode($httpClient->body, associative: true);
        $httpClient->close();

        return $body['count'] ?? null;
    }
}
