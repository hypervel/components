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
 */
class ScalingMultiWorkerTest extends MultiWorkerTestCase
{
    protected int $serverPort = 19515;

    public function testBroadcastReceivedExactlyOncePerClient(): void
    {
        $result = $this->connectOnDifferentWorkers('test-scaling-mw-broadcast');

        $this->triggerEvent('test-scaling-mw-broadcast', 'App\Events\TestEvent', ['msg' => 'hello']);

        // Each client should receive the broadcast exactly once.
        // If the scaling+multi-worker duplicate delivery bug exists,
        // clients would receive it twice (once from Redis, once from
        // the erroneous pipe fan-out of the Redis-delivered message).
        foreach ($result['connections'] as $connection) {
            $message = $this->receiveEvent($connection['client'], 'App\Events\TestEvent');
            $this->assertNotNull($message, 'Client on worker ' . $connection['workerId'] . ' did not receive broadcast');

            $messages = $this->receiveMatchingEvents($connection['client'], 'App\Events\TestEvent', 1);

            $this->assertCount(
                0,
                $messages,
                'Client on worker ' . $connection['workerId'] . ' received ' . (count($messages) + 1) . ' messages instead of 1'
            );
        }

        foreach ($result['connections'] as $connection) {
            $this->disconnect($connection['client']);
        }
    }

    public function testPresenceNotificationReceivedExactlyOncePerClient(): void
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

        // Observer should receive member_added exactly once.
        $message = $this->receiveMemberAdded($observer['client'], 'joiner');
        $this->assertNotNull($message, 'Observer did not receive member_added for joiner');

        $messages = $this->receiveMemberAddedEvents($observer['client'], 'joiner');
        $this->assertCount(
            0,
            $messages,
            'Observer received ' . (count($messages) + 1) . ' member_added messages instead of 1'
        );

        $this->disconnect($observer['client']);
        $this->disconnect($joiner['client']);
    }
}
