<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

/**
 * Integration tests for Reverb with both Redis scaling and multi-worker enabled.
 *
 * This is the only topology that can expose duplicate delivery bugs where
 * Redis-delivered messages are incorrectly pipe-fanned-out to sibling workers.
 *
 * Requires: REVERB_SERVER_PORT=19515 REVERB_SCALING_ENABLED=true REVERB_TEST_WORKER_NUM=2
 *
 * @internal
 * @coversNothing
 */
class ScalingMultiWorkerTest extends MultiWorkerTestCase
{
    protected int $serverPort = 19515;

    public function testBroadcastReceivedExactlyOncePerClient()
    {
        $result = $this->connectOnDifferentWorkers('test-scaling-mw-broadcast');

        $this->triggerEvent('test-scaling-mw-broadcast', 'App\Events\TestEvent', ['msg' => 'hello']);

        // Each client should receive the broadcast exactly once.
        // If the scaling+multi-worker duplicate delivery bug exists,
        // clients would receive it twice (once from Redis, once from
        // the erroneous pipe fan-out of the Redis-delivered message).
        foreach ($result['connections'] as $conn) {
            $messages = $this->recvAll($conn['client'], 1);

            $this->assertCount(
                1,
                $messages,
                'Client on worker ' . $conn['workerId'] . ' received ' . count($messages) . ' messages instead of 1'
            );

            $decoded = json_decode($messages[0], associative: true);
            $this->assertSame('App\Events\TestEvent', $decoded['event']);
        }

        foreach ($result['connections'] as $conn) {
            $this->disconnect($conn['client']);
        }
    }

    public function testPresenceNotificationReceivedExactlyOncePerClient()
    {
        // Observer on one worker
        $observer = $this->connect();
        $this->subscribe($observer['client'], $observer['socketId'], 'presence-scaling-mw-test', [
            'user_id' => 'observer',
            'user_info' => ['name' => 'Observer'],
        ]);

        // Joiner on a different worker
        $joiner = $this->connectOnDifferentWorkerThan($observer['workerId']);
        $this->subscribe($joiner['client'], $joiner['socketId'], 'presence-scaling-mw-test', [
            'user_id' => 'joiner',
            'user_info' => ['name' => 'Joiner'],
        ]);

        // Observer should receive member_added exactly once
        $messages = $this->recvAll($observer['client'], 1);

        $memberAdded = array_filter($messages, function ($msg) {
            $decoded = json_decode($msg, associative: true);

            return ($decoded['event'] ?? null) === 'pusher_internal:member_added';
        });

        $this->assertCount(
            1,
            $memberAdded,
            'Observer received ' . count($memberAdded) . ' member_added messages instead of 1'
        );

        $this->disconnect($observer['client']);
        $this->disconnect($joiner['client']);
    }
}
